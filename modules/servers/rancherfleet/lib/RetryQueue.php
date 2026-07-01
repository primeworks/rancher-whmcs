<?php
/**
 * RetryQueue
 *
 * Manages a persistent queue of failed provisioning phases.
 * Failed phases are stored in the WHMCS database and retried
 * on the next cron run via hooks.php.
 *
 * Queue entries are stored in tblcustomfieldsvalues with fieldid=0
 * using a key prefix of "rf_retry_{serviceid}".
 *
 * Entry format (JSON):
 * {
 *   "service_id":  381,
 *   "phase":       "CreateNamespace",
 *   "namespace":   "whmcs-client-AF4E52",
 *   "attempts":    1,
 *   "max_attempts": 3,
 *   "next_retry":  1716825600,
 *   "last_error":  "Connection timeout",
 *   "created_at":  "2024-01-15 14:32:01"
 * }
 *
 * @version 3.4.0
 */

namespace RancherFleet;

class RetryQueue
{
    const KEY_PREFIX    = 'rf_retry_';
    const MAX_ATTEMPTS  = 3;
    const RETRY_DELAY   = 900; // 15 minutes between retries

    // -----------------------------------------------------------------------
    // Queue Management
    // -----------------------------------------------------------------------

    /**
     * Adds a failed phase to the retry queue.
     *
     * @param int    $serviceId
     * @param string $phase      e.g. 'CreateNamespace', 'BootstrapGithub'
     * @param string $namespace
     * @param string $error      Last error message
     */
    public static function enqueue($serviceId, $phase, $namespace, $error = '')
    {
        $serviceId = (int)$serviceId;
        $key       = self::KEY_PREFIX . $serviceId . '_' . $phase;

        $existing = self::getEntry($serviceId, $phase);
        $attempts = $existing ? ((int)$existing['attempts'] + 1) : 1;

        if ($attempts > self::MAX_ATTEMPTS) {
            Logger::error("RetryQueue: max attempts reached for service={$serviceId} phase={$phase}");
            self::dequeue($serviceId, $phase);
            return;
        }

        $entry = array(
            'service_id'   => $serviceId,
            'phase'        => $phase,
            'namespace'    => $namespace,
            'attempts'     => $attempts,
            'max_attempts' => self::MAX_ATTEMPTS,
            'next_retry'   => time() + self::RETRY_DELAY,
            'last_error'   => $error,
            'created_at'   => $existing ? $existing['created_at'] : date('Y-m-d H:i:s'),
        );

        $value = $key . '|' . json_encode($entry);

        try {
            $exists = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('relid', $serviceId)
                ->where('fieldid', 0)
                ->where('value', 'like', $key . '|%')
                ->exists();

            if ($exists) {
                \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                    ->where('relid', $serviceId)
                    ->where('fieldid', 0)
                    ->where('value', 'like', $key . '|%')
                    ->update(array('value' => $value));
            } else {
                \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                    ->insert(array('fieldid' => 0, 'relid' => $serviceId, 'value' => $value));
            }

            Logger::info("RetryQueue: enqueued service={$serviceId} phase={$phase} attempt={$attempts}");
        } catch (\Exception $e) {
            Logger::error("RetryQueue: enqueue DB error: " . $e->getMessage());
        }
    }

    /**
     * Removes a phase from the retry queue (on success or max attempts).
     *
     * @param int    $serviceId
     * @param string $phase
     */
    public static function dequeue($serviceId, $phase)
    {
        $serviceId = (int)$serviceId;
        $key       = self::KEY_PREFIX . $serviceId . '_' . $phase;

        try {
            \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('relid', $serviceId)
                ->where('fieldid', 0)
                ->where('value', 'like', $key . '|%')
                ->delete();
        } catch (\Exception $e) {
            Logger::error("RetryQueue: dequeue DB error: " . $e->getMessage());
        }
    }

    /**
     * Removes all retry queue entries for a service.
     *
     * @param int $serviceId
     */
    public static function clearAll($serviceId)
    {
        $serviceId = (int)$serviceId;
        $prefix    = self::KEY_PREFIX . $serviceId . '_';

        try {
            \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('relid', $serviceId)
                ->where('fieldid', 0)
                ->where('value', 'like', $prefix . '%')
                ->delete();
        } catch (\Exception $e) {
            Logger::error("RetryQueue: clearAll DB error: " . $e->getMessage());
        }
    }

    /**
     * Returns all queue entries due for retry (next_retry <= now).
     *
     * @return array  Array of entry arrays
     */
    public static function getDueEntries()
    {
        $now     = time();
        $entries = array();

        try {
            $rows = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', 0)
                ->where('value', 'like', self::KEY_PREFIX . '%')
                ->pluck('value');

            foreach ($rows as $row) {
                $pipePos = strpos($row, '|');
                if ($pipePos === false) continue;
                $json  = substr($row, $pipePos + 1);
                $entry = json_decode($json, true);
                if (!is_array($entry)) continue;
                if (isset($entry['next_retry']) && $entry['next_retry'] <= $now) {
                    $entries[] = $entry;
                }
            }
        } catch (\Exception $e) {
            Logger::error("RetryQueue: getDueEntries DB error: " . $e->getMessage());
        }

        return $entries;
    }

    /**
     * Returns all queue entries for a specific service.
     *
     * @param  int   $serviceId
     * @return array
     */
    public static function getServiceEntries($serviceId)
    {
        $serviceId = (int)$serviceId;
        $prefix    = self::KEY_PREFIX . $serviceId . '_';
        $entries   = array();

        try {
            $rows = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('relid', $serviceId)
                ->where('fieldid', 0)
                ->where('value', 'like', $prefix . '%')
                ->pluck('value');

            foreach ($rows as $row) {
                $pipePos = strpos($row, '|');
                if ($pipePos === false) continue;
                $json  = substr($row, $pipePos + 1);
                $entry = json_decode($json, true);
                if (is_array($entry)) {
                    $entries[] = $entry;
                }
            }
        } catch (\Exception $e) {
            // silent
        }

        return $entries;
    }

    /**
     * Returns a single queue entry for a service + phase combination.
     *
     * @param  int    $serviceId
     * @param  string $phase
     * @return array|null
     */
    private static function getEntry($serviceId, $phase)
    {
        $key = self::KEY_PREFIX . $serviceId . '_' . $phase;

        try {
            $row = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('relid', $serviceId)
                ->where('fieldid', 0)
                ->where('value', 'like', $key . '|%')
                ->value('value');

            if ($row) {
                $pipePos = strpos($row, '|');
                $json    = substr($row, $pipePos + 1);
                return json_decode($json, true);
            }
        } catch (\Exception $e) {
            // silent
        }

        return null;
    }
}
