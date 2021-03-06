<?php
/**
 * CI Default Routes
 *
 * PHP Version 5
 *
 * @category Configuration
 * @package  Default_Routes
 * @author   Ken Auberry <Kenneth.Auberry@pnnl.gov>
 * @license  http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link     http://github.com/EMSL-MSC/pacifica-upload-status
 */

defined('BASEPATH') or exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------------
| This file lets you re-map URI requests to specific controller functions.
|
| Typically there is a one-to-one relationship between a URL string
| and its corresponding controller class/method. The segments in a
| URL normally follow this pattern:
|
|   example.com/class/method/id/
|
| In some instances, however, you may want to remap this relationship
| so that a different class/function is called than the one
| corresponding to the URL.
|
| Please see the user guide for complete details:
|
|   http://codeigniter.com/user_guide/general/routing.html
|
| -------------------------------------------------------------------------
| RESERVED ROUTES
| -------------------------------------------------------------------------
|
| There are three reserved routes:
|
|   $route['default_controller'] = 'welcome';
|
| This route indicates which controller class should be loaded if the
| URI contains no data. In the above example, the "welcome" class
| would be loaded.
|
|   $route['404_override'] = 'errors/page_missing';
|
| This route will tell the Router which controller/method to use if those
| provided in the URL cannot be matched to a valid route.
|
|   $route['translate_uri_dashes'] = FALSE;
|
| This is not exactly a route, but allows you to automatically route
| controller and method names that contain dashes. '-' isn't a valid
| class or method name character, so it requires translation.
| When you set this option to TRUE, it will replace ALL dashes in the
| controller and method URI segments.
|
| Examples: my-controller/index -> my_controller/index
|       my-controller/my-method -> my_controller/my_method
*/

$route['default_controller'] = "status_api";
$route['404_override'] = '';

$route['view/overview'] = "status_api/overview";
$route['view/(:any)'] = "status_api/view/$1";
$route['released_data'] = "status_api/overview";
$route['released_data/(:any)'] = "status_api/view/$1";
$route['view/t/(:any)'] = "status_api/view/$1";
$route['view/j/(:any)'] = "status_api/view/$1";
$route['overview'] = "status_api/overview";
$route['data_release'] = "status_api/data_release";
$route['data_release/(:num)'] = "status_api/data_release_single_item/$1";
$route['doi_minting'] = "status_api/doi_minting";
$route['update_local_records/(:num)'] = "ajax_api/save_transient_doi_details/$1";
$route['file_tree'] = "status_api/get_lazy_load_folder";
$route['cart/checkauth'] = "cart_api/check_download_authorization";
$route['cart/delete/(:any)/(:any)'] = "cart_api/delete/$1/$2";
$route['cart/(:any)/(:any)'] = "cart_api/$1/$2";

/* End of file routes.php */
/* Location: ./application/config/routes.php */
