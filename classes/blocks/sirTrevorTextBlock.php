<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class sirTrevorTextBlock extends multilineContentBlock
{
    protected $blockType = 'textBlock';
    protected $mdregex = '/.*/';
    protected $dataregex = '/.*/';
    protected $template = '{"type":"text","data":{"text":"%s"}},';
    protected $editingMaskMap = [
        '/site' => '@site', //use Herbie's Alias to resolve site-root
        '"' => '\"' //unmasked doublequotes break SirTrevor's json-format!
    ];
    protected $mdregexStop = '/^$/';
}