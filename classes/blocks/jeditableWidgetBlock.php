<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class jeditableWidgetBlock extends inlineFilterContentBlock
{
    protected $blockType = 'widgetBlock';
    protected $mdregex = '/(?<=^)\\[blocks\s.*\\]/s';
    protected $dataregex = '/(\[blocks\s.*\])/';
    protected $template = '<iframe seamless id="###id###" onload="iframeLoaded(\'###id###\')" src="/%2$s?editor=iframe" style="width: 100%%; border: 0px solid lime;" scrolling="no">|</iframe>';
    protected $tmplstrSeparator = '|';
    protected $dataFilter = [
        '/\[blocks\s/',
        '/\]/',
        '/\@page\//',
        '/(?:widget=["\']([^"\'].*)?["\'](?:[^\=].*)path=["\']([^"\'].*)["\'])|(?:path=["\']([^"\'].*)?["\'](?:[^\=].*)widget=["\']([^"\'].*)["\'])/',
    ];
    protected $dataReplace = [
        '',
        '',
        '',
        '$2/$1/$3/$4',
    ];
    protected $trim = '/';
    protected $editingMaskMap = [];
}