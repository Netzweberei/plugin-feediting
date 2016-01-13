<?php

/**
 * This file is part of Herbie.
 *
 * (c) Thomas Breuss <www.tebe.ch>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace herbie\plugin\feediting;

use Herbie\DI;
use Herbie\Hook;
//use Herbie\Loader\FrontMatterLoader;
//use Herbie\Menu;
//use Twig_SimpleFunction;

if(!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

class FeeditingPlugin
{
    protected $config = [];

    protected $response;

    protected $alias;

    protected $authenticated = false;

    protected $editableSegments = [];

    protected $replace_pairs = [];

    protected $remove_pairs = [];

    protected $editableContent = [];

    protected $session;

    private $editor = 'Feeditable';

    private $cmd;

    public function __construct()
    {
        $this->config = DI::get('Config');
        $this->alias = DI::get('Alias');
        $this->authenticated = $this->isAuthenticated();
    }

    public function install()
    {
        Hook::attach('pluginsInitialized', [$this, 'onPluginsInitialized']);
        Hook::attach('pageLoaded', [$this, 'onPageLoaded']);
        Hook::attach('outputGenerated', [$this, 'onOutputGenerated']);
    }

    public function onPluginsInitialized()
    {
        // set defaults
        if($this->config->isEmpty('plugins.config.feediting.contentSegment_WrapperPrefix')) {
            $this->config->set('plugins.config.feediting.contentSegment_WrapperPrefix', 'placeholder-');
        }

        if($this->config->isEmpty('plugins.config.feediting.editable_prefix')) {
            $this->config->set('plugins.config.feediting.editable_prefix', 'editable_');
        }

        if($this->config->isEmpty('plugins.config.feediting.contentBlockDimension')) {
            $this->config->set('plugins.config.feediting.contentBlockDimension', 100);
        }

        if($this->config->isEmpty('plugins.config.feediting.dontProvideJquery')) {
            $this->config->set('plugins.config.feediting.dontProvideJquery', false);
        }

        // set editor
        switch($this->config->get('plugins.config.feediting.editor')){
            case 'Htmlforms':
                $this->editor = 'Feeditable';
                break;
            case 'SirTrevor':
                $this->editor = 'SirTrevor';
                break;
            default:
                $this->editor = 'Jeditable';
        }

    }

    // fetch markdown-contents for jeditable
    protected function onPageLoaded( \Herbie\Page $page )
    {
        if($this->isEditable($page)){
            // Disable Caching while editing
            //$this->app['twig']->environment->setCache(false);

            $this->page = $page;
            $this->cmd = @$_REQUEST['cmd'];

            $_segmentid = ( isset($_REQUEST['segmentid']) ) ? $_REQUEST['segmentid'] : '0';
            $_twigify   = ( $this->loadEditableSegments()=='twigify' ) ? true : false;
            if( isset($_REQUEST['cmd']) && is_subclass_of($this->editableContent[$_segmentid], '\\herbie\plugin\\feediting\\classes\\FeeditableContent') && is_callable(array($this->editableContent[$_segmentid], $_REQUEST['cmd']))){
                $this->cmd = $this->editableContent[$_segmentid]->{$this->cmd}();
            }
            $this->editPage($_twigify);
        }
    }

    protected function onWidgetLoaded(\Herbie\Event $event ){

        switch($this->cmd)
        {
            case 'saveWidget':

                // Load widget's specific layout
                $widgetTemplateDir = $event->offsetGet('widgetTemplateDir');
                $pageLoader = $event->offsetGet('pageLoader');
                $pageLoader->addPath($widgetTemplateDir);

                $this->cmd = 'saveAndReturn';
                $this->editableContent = [];
                $this->editWidget($event);
                // Reload changed content
                $this->page->load($this->path);

            case 'getWidgetByName':

                // Load widget's specific layout
                $widgetTemplateDir = $event->offsetGet('widgetTemplateDir');
                $pageLoader = $event->offsetGet('pageLoader');
                $pageLoader->addPath($widgetTemplateDir);

                $this->cmd = 'editWidget';
                $this->editWidget($event);
        }
    }

    protected function onWidgetGenerated(\Herbie\Event $event ){

        switch($this->cmd)
        {
            case 'editWidget':

                $widgetData = $event->offsetGet('widgetData');
                $this->cmd = 'renderWidget';

                // Widget's main template 'index.html' is included into feediting.html
                $this->app['menuItem']->setData(['layout'=>'feediting.html']);
                $this->app['page']->setData($widgetData['data']);
                $this->app['page']->setSegments($widgetData['segments']);


            default:
                return;
        }
    }

    protected function editWidget(\Herbie\Event $event){

        // Disable Caching while editing
        //$this->app['twig']->environment->setCache(false);

        $this->page  = $this->app['page'];
        $this->alias = $this->app['alias'];
        $this->path  = $this->alias->get($event->offsetGet('widgetPath'));

        $_segmentid = ( isset($_REQUEST['segmentid']) ) ? $_REQUEST['segmentid'] : '0';
        $_twigify   = ( $this->loadEditableSegments()=='twigify' ) ? true : false;

        $this->editPage($_twigify);
    }

    private function isEditable(\Herbie\Page $page){

        $path = $page->getPath();
        $alias = substr($path, 0, strpos($path, '/'));
        switch($alias){
            case '@page':
            case '@post':
                return true;
            default:
                return false;
        }
    }

    private function editPage($_twigify=false){

        switch($this->cmd)
        {
            case 'save':
            case 'saveAndReturn':

                if( isset($_POST['id']) )
                {
                    $changed = $this->collectChanges();

                    if( $changed['segmentid'] !== false )
                    {
                        $fheader = $this->getContentfileHeader();
                        $fh = fopen($this->path, 'w');
                        fputs($fh, $fheader);
                        foreach($this->segments as $segmentid => $_staticContent){
                            if( $segmentid != '0' ) {
                                fputs($fh, PHP_EOL."--- {$segmentid} ---".PHP_EOL);
                            }
                            $_modifiedContent[$segmentid] = $this->renderRawContent($this->editableContent[$segmentid]->getSegment(false), $this->editableContent[$segmentid]->getFormat(), true );
                            fputs($fh, $_modifiedContent[$segmentid]);
                        }
                        fclose($fh);
                    }

                    if($this->cmd == 'saveAndReturn') return;
                    $this->cmd = 'reload';

                    $this->page->load($this->page->getPath());
                    $_twigify   = ( $this->loadEditableSegments()=='twigify' ) ? true : false;

                    if($this->editableContent[$changed['segmentid']]->reloadPageAfterSave === true)
                    {
                        foreach($this->segments as $id => $_segment){
                            $this->segments[$id] = $this->renderEditableContent($id, $_segment, 'markdown', $_twigify);
                        }
                        $this->page->setSegments($this->segments);
                        break;
                    }
                    else // deliver partial content for ajax-request
                    {
                        $editable_segment = $this->editableContent[$changed['segmentid']]->getSegment();

                        // render feeditable contents
                        $this->page->setSegments(array(
                            $changed['segmentid'] => $this->renderEditableContent($changed['segmentid'], $editable_segment, $changed['contenttype'], $_twigify)
                        ));

                        $content = $this->page->getSegment($changed['segmentid']);
                        $content = Hook::trigger(Hook::FILTER, 'renderContent', $content->string, $this->page->getData());
                        die($content);
                    }
                }
                break;

            case 'bypass':
                break;

            default:

                foreach($this->segments as $id => $_segment){
                    $this->segments[$id] = $this->renderEditableContent($id, $_segment, 'markdown', $_twigify);
                }
        }

        $this->page->setSegments($this->segments);
        $this->page->nocache = true;
    }

    protected function isAuthenticated()
    {
        return (bool) @$_SESSION['LOGGED_IN'];
    }

    protected function onOutputGenerated($response)
    {
        $this->response = $response;
        $this->self = $this->config->get('plugins.path').'/feediting/';

        $this->getEditablesCssConfig($this->self);
        $this->getEditablesJsConfig($this->self);

        $response->setContent(
            strtr($response->getContent(), $this->replace_pairs)
        );
    }

    private function includeIntoTag($tag=null, $uri)
    {
        $ensureSrcExits = false;

        if(empty($tag)) return;

        if(!isset($this->replace_pairs[$tag]))
            $this->replace_pairs[$tag] = $tag;

        if(substr( $uri, 0, 1 ) == '<')
        {
            // include a tag:
            if(substr( $uri, 0, 2 ) == '</')
                $this->replace_pairs[$tag] = $uri.PHP_EOL.$this->replace_pairs[$tag];
            else
                $this->replace_pairs[$tag] = $this->replace_pairs[$tag].PHP_EOL.$uri.PHP_EOL;

            return;
        }
        else
        {
            // include a path:
            $ref = 'src';
            $filename   = basename($uri);
            $fileAtoms  = explode('.',basename($filename));
            $webdir    = strtr(dirname($uri), array(
                $this->alias->get('@plugin') => ''
            ));

            if( strpos($webdir, '://') > 1 ) {
                $pathPrefix = '';
            } else {
                $pathPrefix = DS.'assets';

                // copy src to assets
                $webpath = $pathPrefix.$webdir.DS.$filename;
                $abspath = $this->alias->get('@web').$webpath;
                if(!file_exists($abspath)){
                    @mkdir(dirname($abspath), 0777, true);
                    copy($uri, $abspath);
                }
            }

            switch(end($fileAtoms))
            {
                case 'css':
                    $tmpl = '<link rel="stylesheet" href="%s" type="text/css" media="screen" title="no title" charset="utf-8">';
                    break;

                case 'js':
                    $tmpl = '<script '.$ref.'="%s" type="text/javascript" charset="utf-8"></script>';
                    break;

                default:
                    return;
            }
        }

        $this->replace_pairs[$tag] = sprintf($tmpl, $pathPrefix.$webdir.DS.$filename).PHP_EOL.$this->replace_pairs[$tag];
    }

    private function getReplacement($mark){
        if(isset($this->replace_pairs[$mark]))
            return $this->replace_pairs[$mark];
        else
            return false;
    }

    private function setReplacement($mark, $replacement){
        $this->replace_pairs[$mark] = $replacement;
        $this->remove_pairs[$mark] = '';
    }
    
    private function getEditablesCssConfig( $pluginPath ){
        return $this->editableContent[0]->getEditablesCssConfig( $pluginPath );
    }

    private function getEditablesJsConfig( $pluginPath ){
        return $this->editableContent[0]->getEditablesJsConfig( $pluginPath );
    }

    private function loadEditableSegments(){

        $this->segments = $this->page->getSegments();

        if( !array_key_exists('0', $this->segments) ){
            $this->segments = array_merge(array('0' => PHP_EOL), $this->segments);
        }

        foreach($this->segments as $segmentid => $_staticContent)
        {
            $contentEditor = "herbie\\plugin\\feediting\\classes\\{$this->editor}Content";
            $this->editableContent[$segmentid] = new $contentEditor($this, $this->page->format, $segmentid);

            if(trim($_staticContent)=='' || trim($_staticContent)==PHP_EOL)
            {
                $_staticContent = $this->editableContent[$segmentid]->editableEmptySegmentContent;
            }

            $this->editableContent[$segmentid]->setContent($_staticContent);
            $this->segments[$segmentid] = $this->editableContent[$segmentid]->getSegment();
        };

        return $this->editableContent[$segmentid]->getSegmentLoadedMsg();
    }

    /**
     * @param string $content
     * @param string $format, eg. 'markdown'
     * @return string
     */
    private function renderRawContent( $content, $format, $stripLF = false )
    {
        $ret = strtr($content, [
            constant(strtoupper($format).'_EOL') => $stripLF ? '' : PHP_EOL,
            'MARKDOWN_EOL' => PHP_EOL
        ]);
        $ret = strtr($ret, $this->remove_pairs);
        return $ret;
    }

    /**
     * @param string $content
     * @param string $format, eg. 'markdown'
     * @return string
     */
    private function renderEditableContent( $contentId, $content, $format, $twigify=false )
    {
        if($twigify && !empty($content)) {
            $twigged = DI::get('Twig')->renderString(strtr($content, array( constant(strtoupper($format).'_EOL') => PHP_EOL )));
            $content = Hook::trigger(Hook::FILTER, 'renderContent', $twigged, $this->page->getData());
        }
        $content = strtr($content, $this->replace_pairs);

        return $this->editableContent[$contentId]->getEditableContainer($contentId, $content);
    }

    private function defineLineFeed($format, $eol)
    {
        $FORMAT_EOL = $this->getLineFeedMarker($format);
        $EDITABLE_FORMAT_EOL = $this->getLineFeedMarker($format, 1);
        // used for saving
        if(!defined($FORMAT_EOL)) define($FORMAT_EOL, $eol);
        // used in in-page-editor
        if(!defined($EDITABLE_FORMAT_EOL)) define($EDITABLE_FORMAT_EOL, $eol);

        $this->replace_pairs[$eol] = '';
        $this->remove_pairs[$eol] = '';
    }

    public function getLineFeedMarker($format, $editable=false){
        return ($editable ? 'EDITABLE_' : '').strtoupper($format).'_EOL';
    }

    private function getContentfileHeader()
    {
        // set current path
        $this->path = $this->alias->get($this->page->getPath());

        // read page's header
        $fh = fopen($this->path, 'r');
        if($fh) {
            $currline = 0;
            $fheader = '';
            $fbody = '';
            while( ($buffer = fgets($fh))!==false )
            {
                $fpart = isset($fpart) ? $fpart : 'header';
                ${'f'.$fpart} .= $buffer;
                $currline++;
                if( $currline > 1 && strpos($buffer, '---')!==false ){
                    $fpart = 'body';
                    break; // don't break, if full body is needed!
                }
            }
        }
        fclose($fh);

        return $fheader;
    }

    private function collectChanges(){

        if(!$this->editableContent[0]){
            return false;
        }

        $posted = $this->editableContent[0]->decodeEditableId($_POST['id']);
        $this->replace_pairs = [];

        if($this->editableContent[$posted['segmentid']]->collectAllChanges === true)
        {
            foreach($this->editableContent as $_segmentid => $_segmentcontent)
            {
                $_elemid = $this->editableContent[$_segmentid]->encodeEditableId($_segmentid);
                if(!$_POST[$_elemid] || !$this->editableContent[$_segmentid]->setContentBlockById($_elemid, (string) $_POST[$_elemid])){
                    return false;
                }
            }
        }
        else
        {
            if(!$this->editableContent[$posted['segmentid']]->setContentBlockById($posted['elemid'], (string) $_POST['value'])){
                return false;
            }
        }

        return array(
            'elemid'        => $posted['elemid'],
            'segmentid'     => $posted['segmentid'],
            'contenttype'   => $posted['contenttype']
        );
    }

    public function getConfig(){
        $config = $this->config->toArray();
        return $config['plugins']['config']['feediting'];
    }

    public function includeIntoHeader($uri){
        $this->includeIntoTag('</head>', $uri);
    }

    public function includeAfterBodyStarts($uri){
        $matches = array(1 => '<body>'); // set default match, overwritten if regex finds something
        preg_match('/(<body[^>]*>)/', $this->response->getContent(), $matches);
        $this->includeIntoTag($matches[1], $uri);
    }

    public function includeBeforeBodyEnds($uri){
        $this->includeIntoTag('</body>', $uri);
    }

    public function __get($attrib){
        switch($attrib){
            case 'app':
            case 'path':
            case 'alias':
            case 'cmd':
            case 'replace_pairs':
            case 'remove_pairs':
                return $this->{$attrib};
                break;
            default:
                return false;
        }
    }

    public function __call($funcname, $args)
    {
        if($this->authenticated === true && DI::get('Request')->getRequestUri() != '/adminpanel' ){
            switch(count($args)){
                case 5:
                    return $this->{$funcname}($args[0], $args[1], $args[2], $args[3], $args[4]);
                case 4:
                    return $this->{$funcname}($args[0], $args[1], $args[2], $args[3]);
                case 3:
                    return $this->{$funcname}($args[0], $args[1], $args[2]);
                case 2:
                    return $this->{$funcname}($args[0], $args[1]);
                case 1:
                default:
                    return $this->{$funcname}($args[0]);
            }
        } else {
            return;
        }
    }
}

(new FeeditingPlugin)->install();