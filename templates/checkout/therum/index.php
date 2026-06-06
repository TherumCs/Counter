<?php
/**
 * Therum checkout (stub). Falls through to Classic for v0.3.0; full port
 * from preview/checkout.html lands next chunk.
 *
 * @var \Counter\Models\Cart $cart
 * @var string $mode
 */
if ( ! defined( 'ABSPATH' ) ) exit;

include __DIR__ . '/../classic/index.php';
