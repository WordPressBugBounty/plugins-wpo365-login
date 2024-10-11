<?php

namespace Wpo\Core;

// prevent public access to this script
defined('ABSPATH') or die();

if (!class_exists('\Wpo\Core\Version')) {

    class Version
    {
        public static $current = '33.0';
    }
}
