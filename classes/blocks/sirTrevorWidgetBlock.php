<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class sirTrevorWidgetBlock extends ownlineButWriteRegex0ContentBlock
{
    protected $blockType = 'widgetBlock';
    protected $mdregex = '/^\{\{\s?widget/';
    protected $dataregex = '/(^.*\([\'\"]{1}(.*)[\'\"]{1}\,?\s?([0-9]*)\).*$)/';
    protected $template = '{"type":"widget","data":{"selected":"%s", "slide":"%2$d"}},';
    protected $editingMaskMap = [];
}