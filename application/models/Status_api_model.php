<?php
/**
 * Pacifica
 *
 * Pacifica is an open-source data management framework designed
 * for the curation and storage of raw and processed scientific
 * data. It is based on the [CodeIgniter web framework](http://codeigniter.com).
 *
 *  The Pacifica-upload-status module provides an interface to
 *  the ingester status reporting backend, allowing users to view
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
 * Status API Model
 *
 * The **Status_api_model** performs most of the heavy lifting for the status site.
 *
 * @category CI_Model
 * @package  Pacifica-upload-status
 * @author   Ken Auberry <kenneth.auberry@pnnl.gov>
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link    http://github.com/EMSL-MSC/pacifica-upload-status
 */
class Status_api_model extends CI_Model
{
    /**
     *  Class constructor
     *
     *  @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    public function __construct()
    {
        parent::__construct();
        $this->local_timezone = 'US/Pacific';
        // $this->load->library('EUS', '', 'eus');
        $this->load->model('Myemsl_api_model', 'myemsl');
        $this->load->helper('item', 'network');

        $this->status_list = array(
            0 => 'Submitted', 1 => 'Received', 2 => 'Processing',
            3 => 'Verified', 4 => 'Stored', 5 => 'Available', 6 => 'Archived',
        );
        $this->load->library('PHPRequests');
    }

    /**
     *  Retrieves a set of transaction entries that correspond to the combination
     *  of instrument, proposal, and timeframe specified in the call
     *
     *  @param int     $instrument_id [description]
     *  @param string  $proposal_id   [description]
     *  @param string  $start_time    [description]
     *  @param string  $end_time      [description]
     *  @param integer $submitter     [description]
     *
     *  @return array   transaction results from search
     *
     *  @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    public function get_transactions($instrument_id, $proposal_id, $start_time, $end_time, $submitter = -1)
    {
        $transactions_url = "{$this->policy_url_base}/status/transactions/search/details?";
        $url_args_array = array(
            'instrument' => isset($instrument_id) ? $instrument_id : -1,
            'proposal' => isset($proposal_id) ? $proposal_id : -1,
            'start' => $start_time,
            'end' => $end_time,
            'submitter' => isset($submitter) ? $submitter : -1,
            'requesting_user' => $this->user_id
        );
        $transactions_url .= http_build_query($url_args_array, '', '&');
        $query = Requests::get($transactions_url, array('Accept' => 'application/json'));
        $results = json_decode($query->body, TRUE);

        return $results;
    }

    /**
     *  Retrieves detailed info for a specified transaction id
     *
     *  @param int $transaction_id The transaction id to grab
     *
     *  @return array transaction details
     *
     *  @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    public function get_formatted_transaction($transaction_id)
    {
        $transactions_url = "{$this->policy_url_base}/status/transactions/search/details?";
        $url_args_array = array(
            'user' => $this->user_id,
            'transaction_id' => $transaction_id
        );
        $transactions_url .= http_build_query($url_args_array, '', '&');

        $query = Requests::get($transactions_url, array('Accept' => 'application/json'));
        $results = json_decode($query->body, TRUE);
        return $results;
    }

    /**
     *  Retrieves a set of proposal entries for a given set of search terms and
     *  a corresponding requester_id
     *
     *  @param string $terms        search terms from the user
     *  @param int    $requester_id the user requesting proposals
     *  @param string $is_active    do we retrieve inactive proposals
     *
     *  @return array   proposal details listing
     *
     *  @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    public function get_proposals_by_name($terms, $requester_id, $is_active = 'active')
    {
        $proposals_url = "{$this->policy_url_base}/status/proposals/search/{$terms}?";
        $url_args_array = array(
            'user' => $this->user_id
        );
        $proposals_url .= http_build_query($url_args_array, '', '&');

        try{
            $query = Requests::get($proposals_url, array('Accept' => 'application/json'));
            // var_dump($query);
            $results = json_decode($query->body, TRUE);
        } catch (Exception $e){
            $results = array();
        }
        return $results;
    }

    public function get_transaction_details($transaction_id)
    {
        $transaction_url = "{$this->policy_url_base}/status/transactions/by_id/{$transaction_id}";
        $url_args_array = array(
            'user' => $this->user_id
        );
        $transaction_url .= http_build_query($url_args_array, '', '&');

        $results = array();

        try{
            $query = Requests::get($transaction_url, array('Accept' => 'application/json'));
            $sc = $query->status_code;
            if($sc / 100 == 2) {
                //good data, move along
                $results = json_decode($query->body, TRUE);

            }elseif($sc / 100 == 4) {
                if($sc == 404) {
                    //transaction not found
                    $results = array();
                }else{
                    //some other input error
                }
            }else{

            }
        } catch (Exception $e){
            //some other error
        }

        return $results;

    }

    public function get_total_size_for_transaction($transaction_id)
    {
        $transaction = $this->get_transaction_details($transaction_id);
        $total_file_size_bytes = 0;
        foreach($transaction['files'] as $file_id => $file_info){
            $total_file_size_bytes += $file_info['size'];
        }
        return $total_file_size_bytes;
    }


    /**
     *  Return the list of files and their associated metadata
     *  for a given transaction id
     *
     *  @param integer $transaction_id The transaction to pull
     *
     *  @return [type]   [description]
     *
     *  @author Ken Auberry <kenneth.auberry@pnnl.gov>
     */
    public function get_files_for_transaction($transaction_id)
    {
        $files_url = "{$this->policy_url_base}/status/transactions/files/{$transaction_id}?";
        $url_args_array = array(
            'user' => $this->user_id
        );
        // $files_url .= http_build_query($url_args_array, '', '&');
        $results = array();
        // try{
            $query = Requests::get($files_url, array('Accept' => 'application/json'));
        if($query->status_code / 100 == 2) {
            $results = json_decode($query->body, TRUE);
        }
        // } catch (Exception $e){
        //     $results = array();
        // }

        if ($results && !empty($results) > 0) {
            $dirs = array();
            foreach ($results as $item_id => $item_info) {
                $subdir = preg_replace('|^proposal\s[^/]+/[^/]+/\d{4}\.\d{1,2}\.\d{1,2}/?|i', '', trim($item_info['subdir'], '/'));
                $filename = $item_info['name'];
                $path = !empty($subdir) ? "{$subdir}/{$filename}" : $filename;
                $path_array = explode('/', $path);
                build_folder_structure($dirs, $path_array, $item_info);
            }

            return array('treelist' => $dirs, 'files' => $results);
        }
    }

}
