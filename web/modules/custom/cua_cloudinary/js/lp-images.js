(function ($) {
  Drupal.behaviors.cua_cloudinary_lp_images = {
    attach: function (context, settings) {
      var imageLinkNode = document.querySelector('.layout-paragraphs-dialog a');

      if (typeof imageLinkNode !== 'undefined') {
        console.log('LP images.');
        var url = imageLinkNode.getAttribute('href');
        document.querySelector('.layout-paragraphs-dialog img').setAttribute('src', url);
        document.querySelector('.layout-paragraphs-dialog img').setAttribute('width', '400');
      }
    }
  };
}(jQuery));
