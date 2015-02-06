<?php

/**
 * This file is part of Herbie.
 *
 * (c) Thomas Breuss <www.tebe.ch>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace herbie\plugin\feediting\classes;

use herbie\plugin\feediting\FeeditingPlugin;

class JeditableContent extends FeeditableContent {

    protected $contentBlocks = [
        "headingBlock" => [
            "template" => '<div class="###class###" id="###id###">%s</div>',
            "mdregex" => '/#+/',
            "dataregex" => '/.*/',
            "insert" => 'array'
        ],
        "textBlock" => [
            "template" => '<div class="###class###" id="###id###">%s</div>',
            "mdregex" => '/.*/',
            "dataregex" => '/.*/',
            "insert" => 'multiline'
        ]
    ];

    public function getEditableContainer($contentId, $content){
        return $content;
    }
}