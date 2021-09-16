(function ($) {
  Drupal.behaviors.cua_cloudinary_main_image = {
    attach: function (context, settings) {
      var imageLinkNode = document.querySelector('.form-item-field-image-main-0' +
        ' .image-widget-data .file--image a');

      if (typeof imageLinkNode !== 'undefined') {
        var url = imageLinkNode.getAttribute('href');
        document.querySelector('.form-item-field-image-main-0 .image-style-thumbnail').setAttribute('src', url);
        document.querySelector('.form-item-field-image-main-0' +
          ' .image-style-thumbnail').setAttribute('width', '400');
      }
    }
  };
}(jQuery));
