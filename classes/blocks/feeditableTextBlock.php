<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class feeditableTextBlock extends arrayContentBlock
{
    protected $blockType = 'textBlock';
    protected $mdregex = '/.*/';
    protected $mdregexStop = '/^$/';
    protected $dataregex = '/.*/';
    protected $template = '<input type="hidden" name="id" value="###id###"/><textarea name="value">%s</textarea><input type="submit" name="cmd" value="save"/>';
}