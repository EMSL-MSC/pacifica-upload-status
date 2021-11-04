<?php
/**
 * Pacifica.
 *
 * Pacifica is an open-source data management framework designed
 * for the curation and storage of raw and processed scientific
 * data. It is based on the [CodeIgniter web framework](http://codeigniter.com).
 *
 *  The Pacifica-upload-status module provides an interface to
 *  the ingester status reporting backend, allowing users to view,
 *  the current state of any uploads they may have performed, as
 *  well as enabling the download and retrieval of that data.
 *
 * PHP Version 5
 *
 * @package Pacifica-upload-status
 * @author  Ken Auberry  <Kenneth.Auberry@pnnl.gov>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link    http://github.com/EMSL-MSC/pacifica-upload-status
 */

/**
 * Cart API Model.
 *
 * The **Cart_api_model** talks to the cart daemon on the backend to make
 * and retrieve carts and files.
 *
 * Cart submission object needs to contain...
 *  - name (string): A descriptive name for the cart
 *  - description (optional, string): optional extended description
 *  - files (array): list of file IDs and corresponding paths to pull
 *
 * @category CI_Model
 * @package  Pacifica-upload-status
 *
 * @author  Ken Auberry <kenneth.auberry@pnnl.gov>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link    http://github.com/EMSL-MSC/pacifica-upload-status
 */
class Cart_api_model extends CI_Model
{
    /**
     *  Class constructor.
     *
     * @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    public function __construct()
    {
        parent::__construct();
        $this->cart_url_base = $this->config->item('internal_cart_url');
        $this->cart_dl_base = $this->config->item('external_cart_url');
        $this->nexus_api_base = $this->config->item('nexus_backend_url');
        $this->load->database('default');
        $this->load->helper('item');
    }

    /**
     *  Generates the an ID for the cart, then makes the appropriate entries
     *  in the cart status database.
     *
     * @param array $cart_submission_json Cart request JSON, converted to array
     *
     * @return string  cart_uuid
     *
     * @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    public function cart_create($cart_owner_identifier, $cart_submission_json)
    {
        $return_array = array(
            'cart_uuid' => null,
            'message' => '',
            'success' => false,
            'retrieval_url' => null
        );
        $local_cart_success = false;
        $new_submission_info = $this->clean_cart_submission($cart_owner_identifier, $cart_submission_json);
        if (!$new_submission_info) {
            $return_array['message'] = 'No files were located for this submission';
            $this->output->set_status_header(410);
            return $return_array;
        }
        $cart_submission_object = $new_submission_info['cleaned_submisson_object'];
        $project_id = $cart_submission_json;
        $cart_uuid = $cart_owner_identifier;

        try {
            $cart_submit_response = $this->submit_to_cartd($cart_uuid, $cart_submission_object);
            log_message('info', json_encode($cart_submit_response));
            $this->submit_to_nexus($cart_uuid, $cart_submission_object);
        } catch (Requests_Exception $e) {
            log_message('error', $e->getMessage());
            if ($e->getType() == 'curlerror') {
                if (preg_match('/(\d+)/i', $e->getMessage(), $matches)) {
                    $curl_error_num = intval($matches[1]);
                    if ($curl_error_num == 6) {
                        $message = "Cart subsystem unavailable. This usually means that there is ";
                        $message .= "a problem with the cart servicing process.";
                    } else {
                        $message = $e->getMessage();
                    }
                } else {
                    $message = $e->getMessage();
                }
            }
            $return_array['message'] = $message;
            $this->output->set_status_header(500);
            return $return_array;
        }

        $return_array['success'] = true;
        $return_array['cart_uuid'] = $cart_uuid;
        $return_array['message'] = "A cart named '{$cart_submission_object['name']}' was successfully created";
        $return_array['retrieval_url'] = "{$this->cart_dl_base}/{$cart_uuid}";

        return $return_array;
    }

    /**
     * [get_active_carts description].
     *
     * @param array $cart_uuid_list list of cart entities to interrogate
     *
     * @return array
     *
     * @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    private function get_cart_info_prev($cart_uuid_list)
    {
        //get the list of any carts from the database
        $select_array = array(
            'c.cart_uuid as cart_uuid',
            'MIN(c.name) as name',
            'MIN(c.description) as description',
            'MIN(c.created) as created',
            'MIN(c.updated) as updated',
            'SUM(ci.file_size_bytes) as total_file_size_bytes',
            'COUNT(ci.file_id) as total_file_count',
        );
        $this->db->select($select_array);
        $this->db->from('cart c');
        $this->db->join('cart_items ci', 'c.cart_uuid = ci.cart_uuid', 'INNER');
        $this->db->group_by('c.cart_uuid');
        $query = $this->db->where_in('c.cart_uuid', $cart_uuid_list)->get();
        $return_array = array();
        foreach ($query->result_array() as $row) {
            $cart_uuid = $row['cart_uuid'];
            $return_array[$cart_uuid] = $row;
        }

        return $return_array;
    }

    /**
     * [get_active_carts description].
     *
     * @param array $cart_uuid_list list of cart entities to interrogate
     *
     * @return array
     *
     * @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    private function get_cart_info($user_id)
    {
        //get the list of any carts from nexus
        $status_url = "{$this->nexus_api_base}{$this->config->item('cart_data_url')}/{$user_id}";
        $headers_list = array('Content-Type' => 'application/json');
        log_message('info', "status_url => ".$status_url);
        $return_array = [];
        try {
            $options = ['verify' => false];
            $query = Requests::get($status_url, $headers_list, $options);
            if ($query->status_code / 100 == 2) {
                log_message('info', 'In return formatter');
                $return_object = json_decode($query->body, true);
                foreach ($return_object['result'] as $entry) {
                    $return_array[$entry['cart_uuid']] = $entry;
                }
            }
        } catch (Requests_Exception $e) {
            log_message('error', $e->getMessage());
        }

        return $return_array;
    }

    /**
     * Retrieve the status for a specified cart entry.
     *
     * @param array $cart_uuid_list simple array list of SHA256 cart uuid's
     *
     * @return array summarized status report from cartd
     *
     * @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    public function cart_status()
    {
        if (!$this->user_id) {
            return [];
        }
        $cart_info = $this->get_cart_info($this->user_id);
        log_message('info', 'In main cart_status');
        log_message('info', json_encode($cart_info));
        $status_lookup = array(
            'waiting' => 'In Preparation',
            'staging' => 'File Retrieval',
            'bundling' => 'File Packaging',
            'ready' => 'Ready for Download',
            'error' => 'Error Condition',
            'deleted' => 'Deleted Cart Entry'
        );
        $status_return = [];
        foreach ($cart_info as $cart_uuid => $entry) {
            log_message('info', $cart_uuid);
            $cart_url = "{$this->cart_url_base}/{$cart_uuid}";
            $response = Requests::head($cart_url);
            // var_dump($response);
            // $status = $response->headers['X-Pacifica-Status'];
            $status = 'ready';
            $message = $response->headers['X-Pacifica-Message'];
            $response_overview = intval($response->status_code / 100);
            // if ($response_overview == 2 && $status != 'error') {
            if ($response_overview == 2) {
                //looks like it went through ok
                $success = true;
            // } elseif ($response->status_code == 404) {
            //     $success = false;
            //     continue;
            } else {
                $success = false;
            }
            if ($status == 'deleted') {
                continue;
            }
            // $this->update_cart_info($cart_uuid, array('last_known_state' => $status));
            $status_return['lookup'] = $status_lookup;
            $status_return['categories'][$status][] = $cart_uuid;
            $status_return['cart_list'][$cart_uuid] = array(
                // 'status' => $status,
                'status' => 'ready',
                'friendly_status' => $status_lookup[$status],
                'message' => $message,
                'success' => $success,
                'response_code' => $response->status_code,
                'name' => $cart_info[$cart_uuid]['name'],
                'description' => $cart_info[$cart_uuid]['description'],
                'total_file_size_bytes' => $cart_info[$cart_uuid]['total_cart_size_bytes'],
                'friendly_file_size' => format_bytes($cart_info[$cart_uuid]['total_cart_size_bytes']),
                'total_file_count' => $cart_info[$cart_uuid]['total_file_count'],
                "associated_project_id" => $cart_info[$cart_uuid]['project_id'],
                'associated_project_name' => $cart_info[$cart_uuid]['project_name'],
                'associated_resource_id' => $cart_info[$cart_uuid]['instrument_id'],
                'associated_resource_name' => $cart_info[$cart_uuid]['instrument_name'],
                'created' => $cart_info[$cart_uuid]['created'],
                'updated' => $cart_info[$cart_uuid]['updated'],
                'user_download_url' => "{$this->cart_dl_base}/{$cart_uuid}",
            );
        }
        return $status_return;
    }

    /**
     * Retrieve the status for a specified cart entry.
     *
     * @param array $cart_uuid_list simple array list of SHA256 cart uuid's
     *
     * @return array summarized status report from cartd
     *
     * @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    public function cart_status_fake()
    {
        if (!$this->user_id) {
            return [];
        }
        $cart_info = $this->get_cart_info($this->user_id);
        log_message('info', 'In main cart_status');
        log_message('info', json_encode($cart_info));
        $status_lookup = array(
            'waiting' => 'In Preparation',
            'staging' => 'File Retrieval',
            'bundling' => 'File Packaging',
            'ready' => 'Ready for Download',
            'error' => 'Error Condition',
            'deleted' => 'Deleted Cart Entry'
        );
        $status_return = [];
        // $cart_info = $cart_info_obj['result'];
        foreach ($cart_info as $cart_uuid => $entry) {
            log_message('info', $cart_uuid);
            $cart_url = "{$this->cart_url_base}/{$cart_uuid}";
            $response = Requests::head($cart_url);
            // var_dump($response);
            $status = $response->headers['X-Pacifica-Status'];
            $message = $response->headers['X-Pacifica-Message'];
            $response_overview = intval($response->status_code / 100);
            if ($response_overview == 2 && $status != 'error') {
                //looks like it went through ok
                $success = true;
            } elseif ($response->status_code == 404) {
                $success = false;
                continue;
            } else {
                $success = false;
            }
            if ($status == 'deleted') {
                continue;
            }
            // $this->update_cart_info($cart_uuid, array('last_known_state' => $status));
            $status_return['lookup'] = $status_lookup;
            $status_return['categories'][$status][] = $cart_uuid;
            $status_return['cart_list'][$cart_uuid] = array(
                'status' => $status,
                'friendly_status' => $status_lookup[$status],
                'message' => $message,
                'success' => $success,
                'response_code' => $response->status_code,
                'name' => $cart_info[$cart_uuid]['name'],
                'description' => $cart_info[$cart_uuid]['description'],
                'total_file_size_bytes' => $cart_info[$cart_uuid]['total_file_size_bytes'],
                'friendly_file_size' => format_bytes($cart_info[$cart_uuid]['total_file_size_bytes']),
                'total_file_count' => $cart_info[$cart_uuid]['total_file_count'],
                'created' => $cart_info[$cart_uuid]['created'],
                'updated' => $cart_info[$cart_uuid]['updated'],
                'user_download_url' => "{$this->cart_dl_base}/{$cart_uuid}",
            );
        }
        // var_dump($status_return);
        return $status_return;
    }

    /**
     * Check for the existence and readiness of a cart instance,
     * and pass along the redirected download url when ready.
     *
     * @param string $cart_uuid SHA256 hash from generate_cart_uuid
     *
     * @return string cart download url
     *
     * @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    public function cart_retrieve($cart_uuid)
    {
        $cart_url = "{$this->cart_url_base}/{$cart_uuid}";
        //check for ready status
        $status_info = $this->cart_status(array($cart_uuid));
        if ($status_info['success'] == true && $status_info['status'] == 'ready') {
            //looks like the cart is ready to download. Let's go.
            $download_url = "{$this->cart_dl_base}/{$cart_uuid}";
        } else {
            $download_url = false;
        }
        $this->output->set_header('X-Pacifica-Status: {$status_info["status"]}');
        $this->output->set_header('X-Pacifica-Message: {$status_info["message"]}');

        return $download_url;
    }

    /**
     * Removes a cart instance from active service.
     *
     * @param string $cart_uuid SHA256 hash from generate_cart_uuid
     *
     * @return bool true/false for success/failure
     *
     * @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    public function cart_delete($cart_uuid)
    {
        $cart_url = "{$this->cart_url_base}/{$cart_uuid}";
        $query = Requests::delete($cart_url);
        $success = false;
        $status_code = $query->status_code;
        if ($query->status_code / 100 == 2) {
            //looks like it went through ok
            $success = true;
        } elseif ($query->status_code / 100 == 5) {
            $status_message = $query->headers('X-Pacifica-Message');
            if ($status_message == 'No cart with uid {$cart_uuid} found') {
                $success = true;
                $status_code = 200;
            }
        } else {
            $success = false;
        }
        if ($success) {
            //gone in the cartd, now mark it in ours
            $deactivate_url = "{$this->nexus_api_base}/{$this->config->item('cart_deactivate_url')}/{$cart_uuid}";
            $headers_list = ['Content-Type' => 'application/json'];
            $options = ['verify' => false];
            $deactivate_query = Requests::get($deactivate_url, $headers_list, $options);
        }
        return $status_code;
    }

    /**
     * Change metadata about the cart, including the name and description.
     *
     * @param string $cart_uuid     SHA256 hash from generate_cart_uuid
     * @param array  $update_object collection of attributes to change
     *
     * @return array updated cart instance entry from database
     *
     * @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    public function update_cart_info($cart_uuid, $update_object)
    {
        $acceptable_names = array(
            'name' => 'name',
            'description' => 'description',
            'last_known_state' => 'last_known_state'
        );
        $clean_update = array();
        foreach ($update_object as $name => $new_value) {
            if (array_key_exists($name, $acceptable_names)) {
                $clean_update[$name] = $new_value;
            }
        }
        if (!empty($clean_update)) {
            $this->db->where('cart_uuid', $cart_uuid);
            $this->db->update('cart', $clean_update);
        }
    }

    /**
     *  Takes the submitted JSON string from the request, cleans it up, and
     *  verifies that all the entries that it needs are present. Returns
     *  the object as an array, or FALSE if invalid.
     *
     * @param string $cart_submission_json Originally submitted cart request JSON
     *
     * @return array   cleaned up cart submission object
     *
     * @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    private function clean_cart_submission($cart_owner_identifier, $cart_submission_json)
    {
        $submission_timestamp = new DateTime();
        log_message('info', "cart identifier => ".$cart_owner_identifier);
        $default_cart_name = "Cart for {$this->fullname}";
        $raw_object = json_decode($cart_submission_json, true);
        $description = array_key_exists('description', $raw_object) ? $raw_object['description'] : '';
        $name = array_key_exists('name', $raw_object) ? $raw_object['name'] : $default_cart_name;
        $file_list = array_key_exists('files', $raw_object) ? $raw_object['files'] : false;
        if (!$file_list) {
            //throw an error, as this is an incomplete cart object
        }
        $file_info = $this->check_and_clean_file_list($file_list);
        if (empty($file_info)) {
            return false;
        }

        $cleaned_object = array(
            'name' => "{$name} ({$submission_timestamp->format('d M Y g:ia')})",
            'files' => $file_info['postable'],
            'user_id' => $this->user_id,
            'cart_uuid' => $cart_owner_identifier,
            'submission_timestamp' => $submission_timestamp->getTimestamp(),
            'total_cart_size_bytes' => $raw_object['dl_total_file_size'],
            'total_file_count' => count($file_list),
            'project_id' => $raw_object['dl_project_id'],
            'instrument_id' => $raw_object['dl_instrument_id'],
            'transaction_id' => $raw_object['dl_transaction_id']
        );
        if (!empty($description)) {
            $cleaned_object['description'] = $description;
        }

        $return_object = array(
            'cleaned_submisson_object' => $cleaned_object,
            'file_details' => $file_info['details'],
        );

        return $return_object;
    }

    /**
     * Check the incoming file list and cleanly format it for submission to the cartd.
     *
     * @param array $file_id_list List of file_id's and paths to request
     *
     * @return array Array containing postable results and full file details
     *
     * @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    private function check_and_clean_file_list($file_id_list)
    {
        $files_url = "{$this->metadata_url_base}/fileinfo/file_details";
        $header_list = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        );
        $query = Requests::post($files_url, $header_list, json_encode($file_id_list));
        if ($query->status_code / 100 != 2) {
            //some kind of error
            return array();
        }
        $results = json_decode($query->body, true);

        $postable_results = array('fileids' => array());

        foreach ($results as $file_entry) {
            $id = $file_entry['file_id'];
            $path = $file_entry['relative_local_path'];
            $hashtype = $file_entry['hashtype'];
            $hashsum = $file_entry['hashsum'];

            $postable_results['fileids'][] = array(
                'id' => $id, 'path' => $path,
                'hashtype' => $hashtype,
                'hashsum' => $hashsum
            );
        }

        $clean_results = array(
            'details' => $results,
            'postable' => $postable_results,
        );

        return $clean_results;
    }

    /**
     * Perform a SHA256 hash on the stringified cart submission object to
     * generate a unique identifier.
     *
     * @param array $cart_submission_object Cleaned and formatted cart submit object
     *
     * @return string SHA256 hash for submit object, formatted as lowercase hex digits
     *
     * @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    private function generate_cart_uuid($cart_submission_object)
    {
        $clean_cart_string = json_encode($cart_submission_object);

        return hash('sha256', $clean_cart_string);
    }

    /**
     * Check for available carts for this user
     *
     * @return array    object of cart entities
     */

    /**
     * Submit the cleaned cart object to the cart daemon server for processing.
     *
     * @param string $cart_uuid              SHA256 hash from generate_cart_uuid
     * @param array  $cart_submission_object The cleaned and formatted cart request object
     *
     * @return bool TRUE on successful request
     *
     * @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    private function submit_to_nexus($cart_uuid, $cart_submission_object)
    {
        // $cart_uuid = $cart_submission_object['cart_uuid'];

        $cart_url = "{$this->nexus_api_base}/add_new_cart_tracking_information";
        $headers_list = array('Content-Type' => 'application/json');
        $cart_submission_object["user_id"] = $this->user_id;
        unset($cart_submission_object['files']);
        log_message('info', json_encode($cart_submission_object));
        try {
            log_message('info', 'cart url => '.$cart_url);
            $options = ['verify' => false];
            $query = Requests::post($cart_url, $headers_list, json_encode($cart_submission_object), $options);
            log_message('info', 'completed submit to NEXUS');
            log_message('info', $query->body);
        } catch (Requests_Exception $e) {
            if ($e->getType() == 'curlerror') {
                if (preg_match('/(\d+)/i', $e->getMessage(), $matches)) {
                    log_message('error', "curl_error => ".$e->getMessage());
                    log_message('error', "status_code => ".$query->status_code);
                    $curl_error_num = intval($matches[1]);
                    if ($curl_error_num == 6) {
                        $message = "NEXUS subsystem unavailable. This usually means that there is a ";
                        $message .= "problem with the NEXUS Backend process.";
                    } else {
                        $message = $e->getMessage();
                    }
                } else {
                    $message = $e->getMessage();
                }
            }
            $return_array['message'] = $message;
            $this->output->set_status_header(500);
            return $return_array;
        }
        return $query;
    }

    /**
     * Submit the cleaned cart object to the cart daemon server for processing.
     *
     * @param string $cart_uuid              SHA256 hash from generate_cart_uuid
     * @param array  $cart_submission_object The cleaned and formatted cart request object
     *
     * @return bool TRUE on successful request
     *
     * @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    private function submit_to_cartd($cart_uuid, $cart_submission_object)
    {
        // $cart_uuid = $cart_submission_object['cart_uuid'];
        $cart_url = "{$this->cart_url_base}/{$cart_uuid}";
        $headers_list = array('Content-Type' => 'application/json');
        $query = Requests::post($cart_url, $headers_list, json_encode($cart_submission_object['files']));
        log_message('info', "From CartD => $query->status_code");
        return $query;
    }
}
