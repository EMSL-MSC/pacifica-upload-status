$(function(){
      
  // $('.tree_holder').each(function(index, el){
    // $(el).fancytree({
      // lazyLoad: get_tree_data
    // });
  // });
  setup_tree_data();
  setup_metadata_disclosure();
  
  window.setInterval(check_cart_status, 30000);
  window.setInterval(update_breadcrumbs, 10000);
  setup_hover_info();
});