<?php
/**
 * Rich-text (WYSIWYG) helper.
 *
 * Every admin screen that edits HTML (products, categories, CMS pages, blogs…) uses this one
 * block so the editor is configured identically everywhere and always loads its runtime from
 * the bundled TinyMCE build (theme, model, icons and plugins included — no CDN, no build step).
 */
declare(strict_types=1);

use Saee\Lib\Url;

if (!function_exists('richtext_scripts')) {
    /**
     * Scripts for TinyMCE plus the init call.
     *
     * @param string $selector CSS selector of the textarea(s) to upgrade.
     * @param int    $height   Editor height in pixels.
     */
    function richtext_scripts(string $selector = 'textarea.richtext', int $height = 380): string
    {
        $base = Url::asset('vendor/tinymce/tinymce');
        return '<script src="' . e($base . '/tinymce.min.js') . '"></script>
<script>
(function () {
  function start() {
    if (!window.tinymce || !document.querySelector(' . json_encode($selector) . ')) { return; }
    tinymce.init({
      selector: ' . json_encode($selector) . ',
      license_key: "gpl",
      height: ' . (int) $height . ',
      menubar: false,
      branding: false,
      promotion: false,
      browser_spellcheck: true,
      convert_urls: false,
      entity_encoding: "raw",
      plugins: "lists advlist link image table code fullscreen searchreplace wordcount autoresize",
      toolbar: "undo redo | blocks | bold italic underline | bullist numlist | link image table | removeformat code fullscreen",
      block_formats: "Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4",
      base_url: ' . json_encode($base) . ',
      suffix: ".min",
      skin_url: ' . json_encode($base . '/skins/ui/oxide') . ',
      content_css: ' . json_encode($base . '/skins/content/default/content.min.css') . ',
      mobile: { menubar: false, plugins: "lists link", toolbar: "undo redo | bold italic | bullist numlist | link" },
      setup: function (editor) {
        editor.on("change blur", function () { editor.save(); });
      }
    });
  }
  if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", start); } else { start(); }
})();
</script>';
    }
}
