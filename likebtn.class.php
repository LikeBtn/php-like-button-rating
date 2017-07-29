<?php
/**
 * PHP script for synchronization of likes on website using LikeBtn.com API
 * https://github.com/LikeBtn/php-like-button-rating
 *
 * Recommended identifier format for buttons: entityname_entityid
 *
 * @author LikeBtn.com <support@likebtn.com>
 */

class LikeBtn {

    protected static $sync_interval = 14400;
    protected static $synchronized = false;
    protected static $time_offset = 57600;
    // API request URL
    protected static $api_url = 'http://api.likebtn.com/api/';

    // Items table name in DB
    protected static $table_item = 'items';

    /**
     * Constructor.
     */
    public function __construct() {

    }

    /**
     * Save some data to DB or anywhere else
     */
    private function saveData($name, $value)
    {

    }

    /**
     * Get data from DB or anywhere else
     */
    private function getData($name)
    {

    }

    /**
     * Running votes synchronization.
     */
    public function runSync() {
        if (!self::$synchronized && $this->timeToSync()) {
            $this->sync();
        }
    }

    /**
     * Check if it is time to sync votes.
     */
    public function timeToSync() {

        $last_sync_time = $this->getData('likebtn_last_sync_time');

        $now = time();
        if (!$last_sync_time) {
            $this->saveData('likebtn_last_sync_time', $now);
            self::$synchronized = true;
            return true;
        } else {

            if ($last_sync_time + self::$sync_interval > $now) {
                return false;
            } else {
                $this->saveData('likebtn_last_sync_time', $now);
                self::$synchronized = true;
                return true;
            }
        }
    }

    /**
     * Retrieve data.
     */
    public function curl($url) {
        if (!function_exists('curl_init')) {
            return json_encode(array(
                'result' => 'error',
                'message' => "curl is not enabled in your PHP"
            ));
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $response_string = curl_exec($ch);

        if ($response_string === false) {
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error_msg = curl_error($ch);
            curl_close($ch);

            return json_encode(array(
                'result' => 'error',
                'message' => 'Curl error: '.$http_code.' - '.$error_msg
            ));
        }

        $response = $this->jsonDecode($response_string);

        if (is_array($response) && !empty($response['body'])) {
            return $response['body'];
        } else {
            return '';
        }
    }


    /**
     * Sync votes from LikeBtn.com to local DB.
     */
    public function syncVotes($email = '', $api_key = '', $site_id = '', $full = false) {
        $sync_result = true;

        $last_sync_time = number_format((int)$this->getData('likebtn_last_sync_time'));

        $updated_after = '';
        if (!$full && $this->getData('likebtn_last_successfull_sync_time')) {
            $updated_after = $this->getData('likebtn_last_successfull_sync_time') - $this->time_offset;
        }

        $url = "output=json&last_sync_time=" . $last_sync_time;
        if ($updated_after) {
            $url .= '&updated_after=' . $updated_after;
        }

        // retrieve first page
        $response = $this->apiRequest('stat', $url, $email, $api_key, $site_id);

        if (!$this->updateVotes($response)) {
            $sync_result = false;
        }

        // retrieve all pages after the first
        if (isset($response['response']['total']) && isset($response['response']['page_size'])) {
            $total_pages = ceil((int) $response['response']['total'] / (int) $response['response']['page_size']);

            for ($page = 2; $page <= $total_pages; $page++) {
                $response = $this->apiRequest('stat', $url . '&page=' . $page, $email, $api_key, $site_id);

                if (!$this->updateVotes($response)) {
                    $sync_result = false;
                }
            }
        }

        $this->saveData('likebtn_last_sync_result', $response['result']);
        if ($sync_result) {
            $this->saveData('likebtn_last_successfull_sync_time', $last_sync_time);
        } else {
            if (!empty($response['message'])) {
                $this->saveData('likebtn_last_sync_message', $response['message']);
            } else {
                $this->saveData('likebtn_last_sync_message', '');
            }
        }

        if ($full) {
            $this->saveData('likebtn_last_sync_time', time());
        }

        return array(
            'result' => $response['result'],
            'message' => $response['message']
        );
    }

    /**
     * Decode JSON.
     */
    public function jsonDecode($jsong_string) {
        if (!is_string($jsong_string)) {
            return array();
        }
        if (!function_exists('json_decode')) {
            return array(
                'result' => 'error',
                'message' => 'json_decode function is not enabled in PHP',
            );
        }

        return json_decode($jsong_string, true);
    }

    /**
     * Update votes in database from API response.
     */
    public function updateVotes($response) {
        $entity_updated = false;

        if (!empty($response['response']['items'])) {
            foreach ($response['response']['items'] as $item) {
                $likes = 0;
                if (!empty($item['likes'])) {
                    $likes = $item['likes'];
                }
                $dislikes = 0;
                if (!empty($item['dislikes'])) {
                    $dislikes = $item['dislikes'];
                }
                $url = '';
                if (isset($item['url'])) {
                    $url = $item['url'];
                }
                $entity_updated = $this->updateItem($item['identifier'], $likes, $dislikes, $url);
            }
        }

        return $entity_updated;
    }

    /**
     * Update entity
     */
    public function updateItem($identifier, $likes = -1, $dislikes = -1, $url = '') 
    {
        global $db;

        preg_match("/^(.*)_(\d+)$/", $identifier, $identifier_parts);

        list($entity_name, $entity_id) = $this->parseIdentifier($identifier);

        $likes = (int)$likes;
        $dislikes = (int)$dislikes;
        
        $likes_minus_dislikes = null;
        if ($likes != -1 && $dislikes != -1) {
            $likes_minus_dislikes = $likes - $dislikes;
        }

        // Check custom item
        $item_db = $db->get_row(
            $db->prepare(
                "SELECT likes, dislikes
                FROM ".$this->table_item."
                WHERE identifier = %s",
                $identifier
            )
        );

        // Custom identifier
        if ($item_db) {

            if ($likes === null || $dislikes === null) {
                if ($item_db) {
                    if ($likes === null) {
                        $likes = $item_db->likes;
                    }
                    if ($dislikes === null) {
                        $dislikes = $item_db->dislikes;
                    }
                }
            }
            if ($likes != -1 && $dislikes != -1) {
                $likes_minus_dislikes = $likes - $dislikes;
            }

            $item_data = array(
                'identifier' => $identifier,
                'url' => $url,
                'likes' => $likes,
                'dislikes' => $dislikes,
                'likes_minus_dislikes' => $likes_minus_dislikes,
                'identifier_hash' => md5($identifier)
            );
            if ($url) {
                $item_data['url'] = $url;
            }

            $update_where = array('identifier' => $item_data['identifier']);
            $update_result = $db->update($this->table_item, $item_data, $update_where);
            if ($update_result) {
                $entity_updated = true;
            } else {
                if (!$item_db) {
                    $insert_result = $db->insert($this->table_item, $item_data);
                    if ($insert_result) {
                        $entity_updated = true;
                    }
                } else {
                    $entity_updated = true;
                }
            }
        }

        return $entity_updated;
    }

    /**
     * Parse identifier.
     */
    public function parseIdentifier($identifier) {
        preg_match("/^(.*)_(\d+)$/", $identifier, $identifier_parts);

        $entity_name = '';
        if (!empty($identifier_parts[1])) {
            $entity_name = $identifier_parts[1];
        }
        
        $entity_id = '';
        if (!empty($identifier_parts[2])) {
            $entity_id = $identifier_parts[2];
        }

        return array(
            $entity_name,
            $entity_id
        );
    }

    /**
     * Check if it is time to sync.
     */
    public function timeToSync($sync_period, $sync_variable) {

        $last_sync_time = $this->getData($sync_variable);

        $now = time();
        if (!$last_sync_time) {
            $this->saveData($sync_variable, $now);
            return true;
        } else {
            if ($last_sync_time + $sync_period > $now) {
                return false;
            } else {
                $this->saveData($sync_variable, $now);
                return true;
            }
        }
    }

    /**
     * Reset likes/dislikes
     *
     */
    public function reset($identifier) {
        $result = false;

        $url = "identifier_filter={$identifier}";
        $response = $this->apiRequest('reset', $url);

        // check result
        if (isset($response['response']['reseted']) && $response['response']['reseted']) {
           $result = $response['response']['reseted'];
        }

        return $result;
    }

    /**
     * Delete item
     *
     */
    public function delete($identifier) {
        $result = false;

        $url = "identifier_filter={$identifier}";
        $response = $this->apiRequest('delete', $url);

        // check result
        if (isset($response['response']['deleted']) && $response['response']['deleted']) {
           $result = $response['response']['deleted'];
        }

        return $result;
    }

    /**
     * Edit likes/dislikes using API
     *
     */
    public function edit($identifier, $type, $value) {
        $response = $this->apiRequest('edit', "identifier_filter={$identifier}&type={$type}&value={$value}");
        return $response;
    }

    /**
     * Get API URL
     *
     * @param type $identifier
     * @return string
     */
    public function apiRequest($action, $request = '', $email = '', $api_key = '', $site_id = '') {
        $apiurl = '';

        $email = urlencode($email);

        $apiurl = $this->api_url . "?email={$email}&api_key={$api_key}&nocache=.php&source=wordpress&site_id={$site_id}&";
        
        $url = $apiurl . "action={$action}&" . $request;

        try {
            $response_string = $this->curl($url);
        } catch (\Exception $e) {
            $response['result'] = 'error';
            $response['message'] = $e->getMessage();
            return $response;
        }
        $response = $this->jsonDecode($response_string);

        if (!isset($response['result'])) {
            $response['result'] = 'error';
            $response['connect_result'] = 'error';
            if (empty($response['message']) && mb_strlen($response_string) < 1000) {
                $response['message'] = $response_string;
            }
        } else {
            $response['connect_result'] = 'success';
        }
        if ($response['result'] == 'error' && !isset($response['message'])) {
            $response['message'] = 'Could not retrieve data from LikeBtn API';
        }

        return $response;
    }

}
