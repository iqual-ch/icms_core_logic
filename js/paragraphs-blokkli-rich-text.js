/**
 * @file
 * paragraphs_blokkli's rich-text.js with cross-origin postMessage().
 *
 * Identical to the contrib file except for the targetOrigin argument: the
 * page runs in an iframe of the blökkli editor, which lives on the frontend
 * domain when Drupal is served on its own backend domain.
 */
document.addEventListener("DOMContentLoaded", function () {
  var domEditableElement = document.querySelector( '.ck-editor__editable' );
  var editorInstance = domEditableElement.ckeditorInstance;
  function getHeight() {
    var editorEl = document.querySelector('.ck-editor')
    if (!editorEl) {
      return 300
    }

    var rect = editorEl.getBoundingClientRect()
    return rect.height
  }

  function onChange() {
    if (window.parent !== window) {
      window.parent.postMessage({
        name: 'blokkli__editable_field_update',
        data: {
          text: editorInstance.data.get(),
        },
      }, '*')
    }
  }

  var prevHeight = 0

  function loop() {
    var newHeight = getHeight()
    if (prevHeight !== newHeight) {
      if (window.parent !== window) {
        window.parent.postMessage({
          name: 'blokkli__' + 'editable_field_update_height',
          data: {
            height: newHeight,
          },
        }, '*')
      }
    }
    prevHeight = newHeight

    window.requestAnimationFrame(loop)
  }

  editorInstance.model.document.on('change', onChange)

  onChange()

  window.requestAnimationFrame(loop)
});
