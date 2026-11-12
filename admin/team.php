<?php
/**
 * Team & Founder section.
 *
 * Everything the public "Meet the founder / our team" blocks show comes from here:
 * name, role, photo, bio, quote, social links, order and where it appears (home / about).
 */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
require __DIR__ . '/includes/crud.php';
admin_require('cms.content');

crud_page([
    'table' => 'team_members',
    'title' => 'Founder & Team',
    'singular' => 'Team member',
    'module' => 'team',
    'order' => 'sort_order ASC, id ASC',
    'search' => ['name', 'designation'],
    'intro' => 'shown on the home page and the About Us page',
    'list' => ['photo', 'name', 'designation', 'role_type', 'show_on_home', 'status'],
    'fields' => [
        'name' => ['type' => 'text', 'label' => 'Full name', 'required' => true, 'max' => 120],
        'designation' => ['type' => 'text', 'label' => 'Designation / title', 'max' => 120, 'help' => 'e.g. Founder & Managing Director'],
        'role_type' => [
            'type' => 'select', 'label' => 'Show in', 'default' => 'team',
            'options' => [
                'founder' => 'Founder / leadership (About Us hero)',
                'team' => 'Team member (About Us grid)',
                'advisor' => 'Advisor / mentor',
            ],
            'help' => 'Founders get the large profile card on About Us.',
        ],
        'photo' => ['type' => 'image', 'label' => 'Photo', 'folder' => 'team'],
        'highlight' => ['type' => 'text', 'label' => 'Stat / highlight', 'max' => 60, 'help' => 'e.g. "20+ years in agri-inputs"'],
        'bio' => ['type' => 'textarea', 'label' => 'Short bio', 'rows' => 5, 'max' => 900],
        'quote' => ['type' => 'textarea', 'label' => 'Quote / message', 'rows' => 3, 'max' => 500],
        'email' => ['type' => 'email', 'label' => 'Email', 'max' => 191],
        'phone' => ['type' => 'text', 'label' => 'Phone', 'max' => 20],
        'linkedin_url' => ['type' => 'text', 'label' => 'LinkedIn URL', 'max' => 255],
        'facebook_url' => ['type' => 'text', 'label' => 'Facebook URL', 'max' => 255],
        'instagram_url' => ['type' => 'text', 'label' => 'Instagram URL', 'max' => 255],
        'x_url' => ['type' => 'text', 'label' => 'X (Twitter) URL', 'max' => 255],
        'show_on_home' => ['type' => 'checkbox', 'label' => 'Show on home page', 'help' => 'Show in the home page founder/team strip'],
        'sort_order' => ['type' => 'number', 'label' => 'Sort order', 'default' => 0],
        'status' => ['type' => 'select', 'label' => 'Status', 'options' => [1 => 'Visible', 0 => 'Hidden'], 'default' => 1],
    ],
]);
