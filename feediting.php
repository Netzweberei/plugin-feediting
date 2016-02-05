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

    private $recursivePageLoads = 0;

    private $parseSubsegments = false;

    private $subsegment_match = '/(-{2}\s+grid\s+.+?-{2})(.*?)(-{2}\s+grid\s+-{2})/msi';

    private $subsegmentid_format = '%s[%s]';

    public function __construct()
    {
        $this->config = DI::get('Config');
        $this->alias  = DI::get('Alias');
        $this->authenticated = $this->isAuthenticated();
    }

    public function install()
    {
        $adminpanelRequested = strpos(DI::get('Request')->getRequestUri(),'/adminpanel') === 0 ? true : false;
        if(!$adminpanelRequested){
            Hook::attach('pluginsInitialized', [$this, 'onPluginsInitialized']);
            Hook::attach('pageLoaded', [$this, 'onPageLoaded']);
            Hook::attach('outputGenerated', [$this, 'onOutputGenerated'],10);
        }
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

        // let the user decide, which editor to use
        $userEditor = $this->config->get('plugins.config.feediting.editor');
        if($userEditor == 'UserEditor' && isset($_GET['editor'])){
            $_SESSION['NWeditor'] = $_GET['editor'];
        }
        switch(@$_SESSION['NWeditor']){
            case 'Htmlforms':
                $userEditor = 'Feeditable';
                break;
            case 'SirTrevor':
                $userEditor = 'SirTrevor';
                break;
            case 'Jeditable':
            default:
                $userEditor = 'Jeditable';
        }

        // set editor
        switch($userEditor){
            case 'Htmlforms':
            case 'Feeditable':
                $this->editor = 'Feeditable';
                break;
            case 'SirTrevor':
                $this->editor = 'SirTrevor';
                $this->parseSubsegments = true;
                break;
            default:
                $this->editor = 'Jeditable';
        }

    }

    protected function onPageLoaded( \Herbie\Page $page )
    {
        $this->recursivePageLoads++;
        if(
            $this->isEditable($page)
            && $this->recursivePageLoads <= 1
        ){

            // Disable Caching while editing
            DI::get('Twig')->getEnvironment()->setCache(false);

            $this->page = $page;
            $this->cmd  = @$_REQUEST['cmd'];

            $segmentId = ( isset($_REQUEST['segmentid']) ) ? $_REQUEST['segmentid'] : '0';
            $_twigify   = ( $this->loadEditableSegments()=='twigify' ) ? true : false;
            if(
                isset($_REQUEST['cmd'])
                && in_array($segmentId, array_keys($this->editableContent))
                && is_subclass_of($this->editableContent[$segmentId], '\\herbie\plugin\\feediting\\classes\\FeeditableContent')
                && is_callable(array($this->editableContent[$segmentId], $_REQUEST['cmd']
            ))){
                $this->cmd = $this->editableContent[$segmentId]->{$this->cmd}();
            }
            $this->editPage($_twigify);
        }
    }

    private function isEditable(\Herbie\Page $page){

        $path  = $page->getPath();
        $alias = substr($path, 0, strpos($path, '/'));
        switch($alias){
            case '@page':
            case '@post':
                return true;
        }
        return false;
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
                        foreach($this->segments as $segmentId => $segmentContents){
                            $_modifiedContent[$segmentId] = '';
                            if( $segmentId != '0' ) {
                                fputs($fh, PHP_EOL."--- {$segmentId} ---".PHP_EOL);
                            }
                            if(is_array($segmentContents)){
                                foreach($segmentContents as $subsegmentId => $subsegmentContent){
                                    $subsegmentId = sprintf($this->subsegmentid_format, $segmentId, $subsegmentId);
                                    if(in_array($subsegmentId, array_keys($this->editableContent))){
                                        $_modifiedContent[$segmentId] .= $this->renderRawContent($this->editableContent[$subsegmentId]->getSegment(false), $this->editableContent[$subsegmentId]->getFormat(), true );
                                    } else {
                                        $_modifiedContent[$segmentId] .= $subsegmentContent;
                                    }
                                }
                            }
                            else{
                                $_modifiedContent[$segmentId] = $this->renderRawContent($this->editableContent[$segmentId]->getSegment(false), $this->editableContent[$segmentId]->getFormat(), true );
                            }
                            fputs($fh, $_modifiedContent[$segmentId]);
                        }
                        fclose($fh);
                    }

                    if($this->cmd == 'saveAndReturn') {
                        return;
                    }

                    $this->cmd = 'reload';

                    $this->page->load($this->page->getPath());
                    $_twigify   = ( $this->loadEditableSegments()=='twigify' ) ? true : false;

                    if($this->editableContent[$changed['segmentid']]->reloadPageAfterSave === false) {
                        // deliver partial content for ajax-request
                        $editable_segment = $this->editableContent[$changed['segmentid']]->getSegment();

                        // render feeditable contents
                        $this->page->setSegments(array(
                                $changed['segmentid'] => $this->renderEditableContent($changed['segmentid'], $editable_segment, $changed['contenttype'], $_twigify)
                            ));

                        $segment = $this->page->getSegment($changed['segmentid']);
                        $content = Hook::trigger(Hook::FILTER, 'renderContent', $segment->string, $this->page->getData());
                        die($content);
                    }

                    foreach($this->segments as $id => $_segment){
                        $this->segments[$id] = $this->renderEditableContent($id, $_segment, 'markdown', $_twigify);
                    }
                    $this->page->setSegments($this->segments);
                    break;

                }
                break;
            case 'bypass':
                break;
            default:
                foreach($this->segments as $id => $_segment){
                    if(is_array($_segment)){
                        // subsegments found!
                        $this->segments[$id] = '';
//                        die(var_dump(array_keys($this->editableContent)));
                        foreach($_segment as $subCtr => $_subsegment){
                            $_subsegmentid = sprintf($this->subsegmentid_format, $id, $subCtr);
                            if(
                                in_array($_subsegmentid, array_keys($this->editableContent))
                                && is_subclass_of($this->editableContent[$_subsegmentid], 'herbie\plugin\feediting\classes\FeeditableContent')
                              ){
                                $this->segments[$id] .= $this->renderEditableContent($_subsegmentid, $_subsegment, 'markdown', $_twigify);
                                $this->segments[$_subsegmentid] = true;
                            } else {
                                $this->segments[$id] .= $this->renderContent($_subsegmentid, $_subsegment, 'markdown', false);
                            }
                        }
                    } else {
                        $this->segments[$id] = $this->renderEditableContent($id, $_segment, 'markdown', $_twigify);
                    }
                }
        }

        $this->setPageSegments();
    }

    protected function setPageSegments()
    {
        $segments = [];
        foreach($this->segments as $segmentId => $editableContainer){
            if( count($mainAndSub = explode('_', $segmentId)) > 1 ){
                $segments[$mainAndSub[0]] .= $editableContainer;
            } else {
                $segments[$segmentId] = $editableContainer;
            }
        }
        $this->page->setSegments($segments);
        $this->page->nocache = true;
    }

    protected function onOutputGenerated($response)
    {

        $this->response = $response;
        $this->self = $this->config->get('plugins.path').'/feediting/';

        if('UserEditor' == $this->config->get('plugins.config.feediting.editor')){
            $this->includeIntoAdminpanel('<div class="feeditingpanel"><a name="FeditableContent">Choose editor:</a><a href="/?editor=SirTrevor">SirTrevor</a><a href="/?editor=Jeditable">Jeditable</a></div>');
        }
        $this->includeIntoHeader($this->self.'assets/css/feediting.css');
        $this->getEditablesCssConfig($this->self);
        $this->getEditablesJsConfig($this->self);

        $response->setContent(
            strtr($response->getContent(), $this->replace_pairs)
        );
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

        $_twigify   = ( $this->loadEditableSegments()=='twigify' ) ? true : false;

        $this->editPage($_twigify);
    }

    protected function isAuthenticated()
    {
        return (bool) @$_SESSION['LOGGED_IN'];
    }

    private function includeIntoTag($tag=null, $uri)
    {
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
            $assetAtoms = $this->provideAsset($uri);
            switch(end($assetAtoms))
            {
                case '.css':
                    $tmpl = '<link rel="stylesheet" href="%s" type="text/css" media="screen" title="no title" charset="utf-8">';
                    break;

                case '.js':
                    $tmpl = '<script src="%s" type="text/javascript" charset="utf-8"></script>';
                    break;

                default:
                    return;
            }
        }

        $this->replace_pairs[$tag] = sprintf($tmpl, implode($assetAtoms)).PHP_EOL.$this->replace_pairs[$tag];
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
        $validKeys = array_keys($this->editableContent);
        $this->editableContent[$validKeys[0]]->getEditablesCssConfig( $pluginPath );
    }

    private function getEditablesJsConfig( $pluginPath ){
        $validKeys = array_keys($this->editableContent);
        $this->editableContent[$validKeys[0]]->getEditablesJsConfig( $pluginPath );
    }

    private function loadEditableSegments(){

        $this->segments = $this->page->getSegments();

        if( !array_key_exists('0', $this->segments) ){
            $this->segments = array_merge(array('0' => PHP_EOL), $this->segments);
        }

        foreach($this->segments as $segmentId => $segmentContents)
        {
            $contentEditor = "herbie\\plugin\\feediting\\classes\\{$this->editor}Content";

            // special case: segment with "subsegments"
            preg_match($this->subsegment_match, $segmentContents, $gridcontent);
            // @todo: test with multiple grids!
            if($this->parseSubsegments && isset($gridcontent[2]))
            {
                $this->segments[$segmentId] = array($gridcontent[1]);
                $cols = preg_split('/\\n+--\\n+/', $gridcontent[2]);
                $offset = 0;
                foreach($cols as $col => $colcontent)
                {
                    if($col > 0) {
                        $this->segments[$segmentId][] = PHP_EOL.'--'.PHP_EOL;
                    }
                    $subsegmentId = sprintf($this->subsegmentid_format, $segmentId, count($this->segments[$segmentId]));
                    $this->editableContent[$subsegmentId] = new $contentEditor($this, $this->page->format, $subsegmentId);
                    $offset = $this->editableContent[$subsegmentId]->setContentBlocks($colcontent, $offset);

                    $this->segments[$segmentId][] = $this->editableContent[$subsegmentId]->getSegment();
                }
                $this->segments[$segmentId][] = PHP_EOL.'-- grid --'.PHP_EOL;
            }
            else
            {
                $this->editableContent[$segmentId] = new $contentEditor($this, $this->page->format, $segmentId);
                $this->editableContent[$segmentId]->setContentBlocks($segmentContents);
                $this->segments[$segmentId] = $this->editableContent[$segmentId]->getSegment();
            }
        }

        // use one of segments for common tasks
        return $this->editableContent[$segmentId]->getSegmentLoadedMsg();
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
    private function renderContent( $contentId, $content, $format, $twigify=false )
    {
        if($twigify && !empty($content)) {
            //$content = DI::get('Twig')->renderString($content);
            $content = strtr($content, array( constant(strtoupper($format).'_EOL') => PHP_EOL ));
            $content = Hook::trigger(Hook::FILTER, 'renderContent', $content, $this->page->getData());
        }
        return $content;
    }

    /**
     * @param string $content
     * @param string $format, eg. 'markdown'
     * @return string
     */
    private function renderEditableContent( $contentId, $content, $format, $twigify=false )
    {
        $ret = '';
        $content = $this->renderContent($contentId, $content, $format, $twigify);
        if(is_array($content)){
            foreach($content as $subcontentId => $subcontent){
                $subsegmentId = sprintf($this->subsegmentid_format, $contentId, $subcontentId);
                if(isset($this->editableContent[$subsegmentId])){
                    $ret .= $this->editableContent[$subsegmentId]->getEditableContainer($subsegmentId,strtr($subcontent, $this->replace_pairs));
                    $this->segments[$subsegmentId] = true;
                } else {
                    $ret .= strtr($subcontent, $this->replace_pairs);
                }
            }
        } elseif(isset($this->editableContent[$contentId])){
            $ret = $this->editableContent[$contentId]->getEditableContainer($contentId,strtr($content, $this->replace_pairs));
        }
        return $ret;
    }

    private function collectChanges(){

        $anyEditor = reset($this->editableContent);
        $posted = $anyEditor->decodeEditableId($_POST['id']);

        $this->replace_pairs = [];
        if($this->editableContent[$posted['segmentid']]->collectAllChanges === true)
        {
            foreach($this->editableContent as $segmentId => $segmentContent)
            {
                $elemId = $this->editableContent[$segmentId]->encodeEditableId($segmentId);
                $subElemId = false;
                if(preg_match('/(.*)\[(.*)\]/', $elemId, $test)){
                    list($domId, $elemId, $subElemId) = $test;
                }

                if($subElemId && $_POST[$elemId][$subElemId]){
                    if(!$this->editableContent[$segmentId]->setContentBlockById($segmentId, (string) $_POST[$elemId][$subElemId])){
                        return false;
                    }
                }
                elseif($_POST[$elemId]) {
                    if(!$this->editableContent[$segmentId]->setContentBlockById($elemId, (string) $_POST[$elemId])){
                        return false;
                    }
                }
            }
        } else {
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

    public function getConfig(){
        $config = $this->config->toArray();
        return $config['plugins']['config']['feediting'];
    }

    public function includeIntoHeader($uri){
        $this->includeIntoTag('</head>', $uri);
    }

    public function includeIntoAdminpanel($html){
        $adminpanelTag = '<div class="adminpanel">';
        $this->replace_pairs[$adminpanelTag] = '<div class="adminpanel">'.$html;
    }

    public function includeAfterBodyStarts($uri){
        $matches = array(1 => '<body>'); // set default match, overwritten if regex finds something
        preg_match('/(<body[^>]*>)/', $this->response->getContent(), $matches);
        $this->includeIntoTag($matches[1], $uri);
    }

    public function includeBeforeBodyEnds($uri){
        $this->includeIntoTag('</body>', $uri);
    }

    public function provideAsset($uri){
        // include a path:
        $pathinfo = pathinfo($uri);
        $webdir    = strtr(dirname($uri), array(
                $this->alias->get('@plugin') => ''
            ));

        if( strpos($webdir, '://') > 1 ) {
            $pathPrefix = '';
        } else {
            $pathPrefix = DS.'assets';

            // copy src to assets
            $webpath = $pathPrefix.$webdir.DS.$pathinfo['basename'];
            $abspath = $this->alias->get('@web').$webpath;
            if(!file_exists($abspath)){
                @mkdir(dirname($abspath), 0777, true);
                copy($uri, $abspath);
            }
        }
        return [$pathPrefix,$webdir.DS,$pathinfo['filename'],'.'.$pathinfo['extension']];
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
        if($this->authenticated === true ){
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