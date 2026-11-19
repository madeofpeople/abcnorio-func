<?php

$verify = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$eventScope = get_term_by('slug', 'event', 'sidebar_scope');
$pageScope = get_term_by('slug', 'page', 'sidebar_scope');
$verify($eventScope instanceof WP_Term && $pageScope instanceof WP_Term, 'Event and Page scopes must exist.');
$created = [];
$create = static function (array $args) use (&$created): int {
    $result = wp_insert_post($args, true);
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }
    $created[] = $result;
    return $result;
};
$scopes = static fn(int $id) => wp_get_object_terms($id, 'sidebar_scope', ['fields' => 'ids']);

try {
    foreach (['auto-draft', 'draft'] as $status) {
        $id = $create(['post_type' => 'event', 'post_status' => $status, 'post_title' => 'Sidebar default regression check']);
        $verify($scopes($id) === [$eventScope->term_id], 'New events must start with the Event scope.');
        wp_set_object_terms($id, [], 'sidebar_scope');
        wp_update_post(['ID' => $id, 'post_title' => 'Updated regression check']);
        $verify($scopes($id) === [], 'Updates must not restore a removed scope.');
    }

    $id = $create([
        'post_type' => 'event',
        'post_title' => 'Explicit sidebar regression check',
        'tax_input' => ['sidebar_scope' => [$pageScope->term_id]],
    ]);
    $verify($scopes($id) === [$pageScope->term_id], 'Explicit scopes must not be overwritten.');

    $id = $create(['post_type' => 'article', 'post_title' => 'Other post type regression check']);
    $verify($scopes($id) === [], 'Other post types must not receive the Event scope.');

    foreach ([null, [], [$pageScope->term_id]] as $requestedScopes) {
        $request = new WP_REST_Request('POST', '/wp/v2/events');
        $request->set_body_params(['title' => 'REST sidebar regression check', 'status' => 'draft']);
        if ($requestedScopes !== null) {
            $request->set_param('sidebar-scopes', $requestedScopes);
        }
        $response = rest_do_request($request);
        $verify($response->get_status() === 201, 'REST event creation must succeed.');
        $id = $response->get_data()['id'];
        $created[] = $id;
        $expected = $requestedScopes ? $requestedScopes : [$eventScope->term_id];
        $verify($scopes($id) === $expected, 'REST creation must default empty scopes and preserve explicit scopes.');
    }
} finally {
    foreach ($created as $id) {
        wp_delete_post($id, true);
    }
}

echo "Event sidebar default checks passed.\n";
