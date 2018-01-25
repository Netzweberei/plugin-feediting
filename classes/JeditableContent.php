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
        // Recursion! Update segments-uri
        $this->plugin->setSegmentsUri(true);
        $this->pluginConfig['contentSegment_WrapperPrefix'] = $this->plugin->getSegmentsUri().'jeditable';
        $this->pluginConfig['editable_prefix'] = $this->plugin->getSegmentsUri().'-'.'jeditable';
        parent::init();
    }

    protected function registerContentBlocks()
    {
        $this->contentBlocks = [
            new blocks\jeditableHeadingBlock($this),
            new blocks\jeditableIaWriterBlock($this),
//            new blocks\jeditableBlocksBlock($this),
            new blocks\jeditableWidgetBlock($this),
            new blocks\jeditableTextBlock($this),
        ];
    }

    public function load()
    {
        extract($this->decodeEditableId($_REQUEST['id']));

        $ret = $this->blocks[$elemid] ? $this->blocks[$elemid] : '';

        die($ret);
    }

    public function save()
    {
        return 'save';
    }

    public function getEditableContainer($contentId, $content)
    {
        // bugfix: Remask masked shortcodes, eg "[[foo]]"
        // @todo: do this within the respective container
        $content = preg_replace('/\\[([^\\]].*)\\]/', '[[$1]]', $content);

        if ($this->plugin->cmd == 'reload' && !$this->reloadPageAfterSave) {
            return
                $content.
                $this->setJSEditableConfig($contentId, $this->plugin->getSegmentsUri());
        } else {
            $this->plugin->includeBeforeBodyEnds(
                $this->setJSEditableConfig($this->segmentid, $this->plugin->getSegmentsUri())
            );
            return $content;
        }
    }

    protected function setJSEditableConfig($containerId = 0, $containeruri = '')
    {
        $blockSanitizer     = ['[' => '\\\[', ']' => '\\\]'];
        $fnameSanitizer     = ['-' => ''];
        $blockSelector      = '.'.$this->pluginConfig['editable_prefix'].$this->format.'-'.$containerId;
        $segmentSelector    = $this->pluginConfig['contentSegment_WrapperPrefix'].$containerId;
        $blockSelector      = strtr($blockSelector, $blockSanitizer);
        $segmentFunctionName= strtr($segmentSelector, $fnameSanitizer);

        $ret = <<<EOT
<script type="text/javascript" charset="utf-8">
            function withContainer$segmentFunctionName() {
    $("$blockSelector").editable("/$containeruri?cmd=save&renderer=markdown", {
        indicator : "<img src=\'/assets/feediting/libs/jquery_jeditable-master/img/indicator.gif\'>",
        loadurl   : "/$containeruri?cmd=load&segmentid=$containerId&renderer=markdown",
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
</script>
EOT;

        return $ret;
    }

    public function getEditablesCssConfig($path = null)
    {
        $this->plugin->includeIntoHeader($path.'libs/simplemde/dist/simplemde.min.css');
    }

    public function getEditablesJsConfig($path = null)
    {
        // Due to my basic programming skills, the files have to be included in reverse order ;-)
        $this->plugin->provideAsset($path.'libs/jquery_jeditable-master/img/indicator.gif');
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