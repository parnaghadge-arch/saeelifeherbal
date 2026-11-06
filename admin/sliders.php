<?php
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
require __DIR__ . '/includes/crud.php';
admin_require('cms.sliders');

crud_page([
    "table" => "homepage_sliders", "title" => "Hero Sliders", "singular" => "Slide", "module" => "sliders",
    "order" => "sort_order ASC, id ASC", "search" => ["title", "subtitle"], "list" => ["image_desktop", "title", "sort_order", "status"],
    "intro" => "Unlimited slides with desktop/mobile imagery, scheduling and animation",
    "fields" => [
        "image_desktop" => ["type" => "image", "label" => "Desktop image", "required" => true, "folder" => "sliders"],
        "image_mobile" => ["type" => "image", "label" => "Mobile image", "folder" => "sliders"],
        "title" => ["type" => "text", "label" => "Heading", "max" => 160],
        "subtitle" => ["type" => "text", "label" => "Sub heading", "max" => 200],
        "description" => ["type" => "textarea", "label" => "Description", "max" => 400],
        "button_text" => ["type" => "text", "label" => "Button 1 label", "max" => 40],
        "button_url" => ["type" => "text", "label" => "Button 1 URL", "max" => 200],
        "button2_text" => ["type" => "text", "label" => "Button 2 label", "max" => 40],
        "button2_url" => ["type" => "text", "label" => "Button 2 URL", "max" => 200],
        "text_align" => ["type" => "select", "label" => "Text alignment", "options" => ["left" => "Left", "center" => "Center", "right" => "Right"], "default" => "left"],
        "overlay_opacity" => ["type" => "number", "label" => "Overlay opacity (0-90)", "default" => 45],
        "overlay_color" => ["type" => "text", "label" => "Overlay colour", "default" => "#0d2417", "max" => 20],
        "transition" => ["type" => "select", "label" => "Transition", "options" => ["fade" => "Fade", "slide" => "Slide", "zoom" => "Zoom"], "default" => "fade"],
        "text_animation" => ["type" => "select", "label" => "Text animation", "options" => ["fade-up" => "Fade up", "fade-in" => "Fade in", "slide-left" => "Slide left", "none" => "None"], "default" => "fade-up"],
        "duration" => ["type" => "number", "label" => "Duration (ms)", "default" => 6000],
        "show_heading" => ["type" => "checkbox", "label" => "Show heading", "default" => 1],
        "show_description" => ["type" => "checkbox", "label" => "Show description", "default" => 1],
        "show_buttons" => ["type" => "checkbox", "label" => "Show buttons", "default" => 1],
        "publish_from" => ["type" => "datetime", "label" => "Publish from (optional)"],
        "publish_to" => ["type" => "datetime", "label" => "Publish until (optional)"],
        "sort_order" => ["type" => "number", "label" => "Sort order", "default" => 0],
        "status" => ["type" => "select", "label" => "Status", "options" => [1 => "Active", 0 => "Inactive"], "default" => 1],
    ],
]);
