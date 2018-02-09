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

class JeditableContent extends FeeditableContent
{
    public $reloadPageAfterSave = false;

    protected $segmentLoadedMsg = 'twigify';

    protected function init()
    {
        $this->plugin->setSegmentsUri(true);
        $uriSanitizer = [
            '[' => '\\\[',
            ']' => '\\\]',
            '/' => '',
            '-' => ''
        ];
        $segmentsUri = ltrim(strtr($this->plugin->getSegmentsUri(), $uriSanitizer), '.');
        $this->pluginConfig['contentSegment_WrapperPrefix'] = $segmentsUri.'jeditable';
        $this->pluginConfig['editable_prefix'] = $segmentsUri.'-'.'jeditable';
        parent::init();
    }

    protected function registerContentBlocks()
    {
        $this->contentBlocks = [
            new blocks\jeditableHeadingBlock($this),
            new blocks\jeditableIaWriterBlock($this),
            new blocks\jeditableBlocksBlock($this),
            #new blocks\jeditableWidgetBlock($this),
            new blocks\jeditableTextBlock($this),
        ];
    }

    /**
     * @param array $moreExcludeBlocks
     */
    protected function registerPassthruBlocks($moreExcludeBlocks=[])
    {
        $moreExcludeBlocks = [
            new blocks\passthruContentBlock($this, '/^\[listing.*$/')
        ];
        parent::registerPassthruBlocks($moreExcludeBlocks);
    }

    /**
     * print raw content
     */
    public function load()
    {
        extract($this->decodeEditableId($_REQUEST['id']));
        $ret = $this->blocks[$elemid] ? $this->blocks[$elemid] : '';
        $ret = trim($ret) == trim($this->plugin->editableEmptySegmentContent) ? '' : $ret;
        die($ret);
    }

    /**
     * @return string
     */
    public function save()
    {
        return 'save';
    }

    /**
     * @param $contentId
     * @param $content
     * @return string
     */
    public function getEditableContainer($contentId, $content)
    {
        // bugfix: Remask masked shortcodes, eg "[[foo]]"
        // @todo: do this within the respective container
        $content = preg_replace('/\\[\\[([^\\]].*)\\]\\]/', '[[$1]]', $content);

        if ($this->plugin->cmd == 'reload' && !$this->reloadPageAfterSave) {
            return
                $content.
                $this->setJSEditableConfig($contentId, $this->plugin->getSegmentsUri());
        }
        else {
            $this->plugin->includeBeforeBodyEnds(
                $this->setJSEditableConfig($this->segmentid, $this->plugin->getSegmentsUri())
            );
            return $content;
        }
    }

    protected function setJSEditableConfig($containerId = 0, $containeruri = '')
    {
        $blockSelector      = '.'.$this->pluginConfig['editable_prefix'].$this->format.'-'.$containerId;
        $segmentSelector    = $this->pluginConfig['contentSegment_WrapperPrefix'].$containerId;

        $ret = <<<EOT
<script type="text/javascript" charset="utf-8">
<!--
function withContainer$segmentSelector() {
    $("$blockSelector").editable("$containeruri?cmd=save&renderer=markdown", {
        indicator : "<img src=\'/assets/feediting/libs/jquery_jeditable-master/img/indicator.gif\'>",
        loadurl   : "$containeruri?cmd=load&segmentid=$containerId&renderer=markdown",
        type      : "simplemde",
        submit    : "OK",
        cancel    : "Cancel",
        tooltip   : "Click to edit...",
        ajaxoptions : {
            replace : "with",
            container : ".$segmentSelector",
            run: "callAllFunctions();"
        }
    });
}
//-->
</script>
EOT;
        return $ret;
    }

    /**
     * @param null $path
     */
    public function getEditablesCssConfig($path = null)
    {
        $this->plugin->includeIntoHeader($path.'libs/simplemde/dist/simplemde.min.css');
    }

    /**
     * @param null $path
     */
    public function getEditablesJsConfig($path = null)
    {
        // Due to my lowlevel-programming-skills, the files have to be included in reverse order ;-)
        $this->plugin->provideAsset($path.'libs/jquery_jeditable-master/img/indicator.gif');
        $this->plugin->includeBeforeBodyEnds($path.'assets/js/feediting.js');
        $this->plugin->includeBeforeBodyEnds($path.'libs/jquery_jeditable-master/jquery.jeditable.simplemde.js');
        $this->plugin->includeBeforeBodyEnds($path.'libs/simplemde/dist/codemirror.inline-attachment.js');
        $this->plugin->includeBeforeBodyEnds($path.'libs/simplemde/dist/inline-attachment.js');
        $this->plugin->includeBeforeBodyEnds($path.'libs/simplemde/dist/simplemde.min.js');
        $this->plugin->includeBeforeBodyEnds($path.'libs/jquery_jeditable-master/jquery.jeditable.js');
        if (false === $this->pluginConfig['dontProvideJquery']) {
            $this->plugin->includeBeforeBodyEnds($path.'libs/jquery/jquery-1.8.2.js');
        }
    }
}