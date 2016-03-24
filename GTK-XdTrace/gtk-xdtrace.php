<?php
/*
 *  Copyright (C) 2013  Jeannie Boffel <jboffel@gmail.com>
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */


/*
 * Exception to the previous licence.
 * This list of function is subject of the following license: addToFunction, getFunctions, addStatsExit, addStatsEnter
 *
   +----------------------------------------------------------------------+
   | Xdebug                                                               |
   +----------------------------------------------------------------------+
   | Copyright (c) 2002-2016 Derick Rethans                               |
   +----------------------------------------------------------------------+
   | This source file is subject to version 1.01 of the Xdebug license,   |
   | that is bundled with this package in the file LICENSE, and is        |
   | available at through the world-wide-web at                           |
   | http://xdebug.derickrethans.nl/license.php                           |
   | If you did not receive a copy of the Xdebug license and are unable   |
   | to obtain it through the world-wide-web, please send a note to       |
   | xdebug@derickrethans.nl so we can mail you a copy immediately.       |
   +----------------------------------------------------------------------+
   | Authors:  Derick Rethans <derick@xdebug.org>                         |
   +----------------------------------------------------------------------+
 */

ini_set('memory_limit', '2048M');

$gtkBuilder = new GtkBuilder();

$gtkBuilder->add_from_file(dirname(__FILE__) . '/GTK-XdTrace3.gtkbuilder');

class GTK_XdTrace
{

    protected $glade;

    protected $fileName = NULL;

    protected $steps = array();

    protected $pointer = 1;

    protected $currentFile = array();

    protected $globalFileNameListInOrder = array();

    protected $listOfMapping = array();

    protected $currentProgress = 0;

    protected $progressBar = null;

    protected $dialog = null;

    protected $folderToMap = array();

    protected $folderToMapBkP = array();

    protected $time = 0;

    protected $previousFile = array();

    protected $stackLinksInfo = array();

    protected $sortKeyGetFunction = null;

    protected $cursors = array();

    protected $filterModels = array();

    //Thanks Derick @http://xdebug.org
    /**
     * Stores the last function, time, memory for the entry point per
     * stack depth. int=>array(string, float, int).
     */
    protected $stack;

    /**
     * Stores per function the total time and memory increases and calls
     * string=>array(float, int, int)
     */
    protected $functions;

    /**
     * Stores which functions are on the stack
     */
    protected $stackFunctions;

    function __construct ($glade)
    {
        $this->glade = $glade;

        $combo = $this->glade->get_object('steptofile');
        $combo->set_active(0);
        $combo = $this->glade->get_object('steptostepline');
        $combo->set_active(0);
        $combo = $this->glade->get_object('mapfolderprefix');
        $combo->set_active(0);
        $combo = $this->glade->get_object('listofmap');
        $combo->set_active(0);

        $this->cursors['link'] = new GdkCursor(Gdk::HAND2);
        $this->cursors['textView'] = new GdkCursor(Gdk::XTERM);
    }

    public function openFile ($obj)
    {
        $this->fileName = NULL;

        $this->steps = array();

        $this->pointer = 1;

        $this->currentFile = array();

        $this->globalFileNameListInOrder = array();

        $this->listOfMapping = array();

        $this->currentProgress = 0;

        $this->progressBar = null;

        $this->dialog = null;

        $this->time = 0;

        $this->folderToMap = array();

        $this->folderToMapBkP = array();

        $this->fileName = $obj->get_filename();

        $this->previousFile = array();

        $this->stackLinksInfo = array();

        $this->stack = array();

        $this->stack[-1] = array( '', 0, 0, 0, 0, 0 );
        $this->stack[ 0] = array( '', 0, 0, 0, 0, 0 );

        $this->stackFunctions = array();

        $this->functions = array();

        $this->sortKeyGetFunction = null;

        $this->filterModels = array();

        $this->startProgressBar();

        $this->processTraceFile();

        $this->stopProgressBar();

        $this->showFileStepList();

        $this->showFolderToMap();

        $this->fillListOfMap();

        $this->startShowStory();
    }

    protected function showFileStepList ()
    {
        $combo = $this->glade->get_object('steptofile');
        $store = new GtkListStore(Gobject::TYPE_STRING);
        $combo->set_model($store);

        $tmp = array_merge(array_unique($this->globalFileNameListInOrder));

        foreach ($tmp as $key => $fileName) {
            $combo->append_text($fileName);
        }

        $combo = new GtkEntryCompletion();

        $entry = $this->glade->get_object('selectfolderautocomplete');

        $entry->set_completion($combo);

        $model = new GtkListStore(Gobject::TYPE_STRING);

        $combo->set_model($model);
        $combo->set_text_column(0);
        $combo->set_match_func(array(&$this, "matchFunc"));
        $combo->set_popup_set_width(false);
        $combo->connect('match-selected', array(&$this, 'onMatchSelectedBkP'));

        foreach($tmp as $key => $fileName) {
            $model->append(array($fileName));
        }

        $this->folderToMapBkP = $tmp;
    }

    public function onMatchSelected($widget, $model, $iter)
    {
        $folder = $model->get_value($iter, 0);

        $combo = $this->glade->get_object('mapfolderprefix');

        $index = array_search($folder, $this->folderToMap);

        if ($index === false) {
            return;
        }

        $combo->set_active($index+1);

        $this->showMapFileChooserBox();

    }

    public function onMatchSelectedBkP($widget, $model, $iter)
    {
        $folder = $model->get_value($iter, 0);

        $combo = $this->glade->get_object('steptofile');

        $index = array_search($folder, $this->folderToMapBkP);

        if ($index === false) {
            return;
        }

        $combo->set_active($index);

        $this->showStepsInCB2();

    }

    function matchFunc($completion, $key_string, $iter) {
        $model = $completion->get_model();

        if(stristr($model->get_value($iter, 0), $key_string)) {
            return true;
        }
        return false;
    }

    protected function buildListOfFolders($folderList)
    {

        $tmp = array();

        foreach($folderList as $folder) {
            $subRun = dirname($folder);
            $tmp[] = $subRun;
            while (dirname($subRun) != $subRun) {
                $subRun = dirname($subRun);
                $tmp[] = $subRun;
            }
        }

        sort($tmp);

        return array_merge(array_unique($tmp));
    }

    protected function myDirname($value, $key) {
        return dirname($value);
    }

    protected function showFolderToMap ()
    {
        $combo = $this->glade->get_object('mapfolderprefix');
        $store = new GtkListStore(Gobject::TYPE_STRING);
        $combo->set_model($store);
        $tmp = array_unique($this->globalFileNameListInOrder);
        array_walk($tmp, array(&$this, 'myDirname'));
        $tmp = array_unique($tmp);
        $tmp = $this->buildListOfFolders($tmp);

        $combo->append_text("List of folder mappable");
        foreach ($tmp as $key => $fileName) {
            $combo->append_text($fileName);
        }

        $combo->set_active(0);

        $combo = new GtkEntryCompletion();

        $entry = $this->glade->get_object('selectfolderinlist');

        $entry->set_completion($combo);

        $model = new GtkListStore(Gobject::TYPE_STRING);
        $combo->set_model($model);
        $combo->set_text_column(0);
        $combo->set_match_func(array(&$this, "matchFunc"));
        $combo->set_popup_set_width(false);
        $combo->connect('match-selected', array(&$this, 'onMatchSelected'));

        foreach($tmp as $key => $fileName) {
            $model->append(array($fileName));
        }

        $this->folderToMap = $tmp;
    }

    public function showMapFileChooserBox()
    {
        $combo = $this->glade->get_object('mapfolderprefix');
        $filename = $combo->get_active_text();

        if ("List of folder mappable" != $filename) {

            $dialog = new GtkFileChooserDialog("Select destination folder", null, Gtk::FILE_CHOOSER_ACTION_SELECT_FOLDER, array(Gtk::STOCK_OK, Gtk::RESPONSE_OK), null);
            $dialog->show_all();
            if ($dialog->run() == Gtk::RESPONSE_OK) {
                $selectedFolder = $dialog->get_filename();
                $selectedFolder = str_replace(DIRECTORY_SEPARATOR, '/', $selectedFolder);
                $this->listOfMapping[$filename] = $selectedFolder;
                $this->fillListOfMap();
            }
            $dialog->destroy();

            $combo->set_active(0);
        }
    }

    public function deleteOneMapping()
    {
        $combo = $this->glade->get_object('listofmap');
        $map = $combo->get_active_text();

        if ("List of existing map" != $map) {

            $dialog = new GtkMessageDialog($this->glade->get_object('window1'), Gtk::DIALOG_NO_SEPARATOR, Gtk::MESSAGE_QUESTION, Gtk::BUTTONS_OK_CANCEL, "Delete selected mapping?");
            $dialog->show_all();
            if ($dialog->run() == Gtk::RESPONSE_OK) {
                foreach($this->listOfMapping as $origin => $destination) {
                    if ("$origin -> $destination" == $map) {
                        unset($this->listOfMapping[$origin]);
                        $this->fillListOfMap();
                    }
                }
            } else {
                $combo = $this->glade->get_object('listofmap');
                $combo->set_active(0);
            }
            $dialog->destroy();
        }
    }

    protected function fillListOfMap()
    {
        $combo = $this->glade->get_object('listofmap');
        $store = new GtkListStore(Gobject::TYPE_STRING);
        $combo->set_model($store);

        $combo->append_text("List of existing map");

        foreach ($this->listOfMapping as $origin => $destination) {
            $combo->append_text($origin . " -> " . $destination);
        }

        $combo->set_active(0);
    }

    public function showComboStep ()
    {
        $combo = $this->glade->get_object('steptostepline');
        $stepraw = $combo->get_active_text();

        $step = substr($stepraw, 6, strpos($stepraw, ',') - 6);

        $this->pointer = $step;

        $this->showStoryStep($step);
    }

    public function showStepsInCB2 ()
    {
        $combo = $this->glade->get_object('steptofile');
        $filename = $combo->get_active_text();

        $combo = $this->glade->get_object('steptostepline');
        $store = new GtkListStore(Gobject::TYPE_STRING);
        $combo->set_model($store);

        foreach ($this->steps as $key => $step) {
            if ($step['filename'] == $filename)
                $tmp[$key] = $step['line'];
        }

        $tmp = array_unique($tmp);

        foreach ($tmp as $key => $line) {
            $combo->append_text('Step: ' . $key . ', line: ' . $line);
        }
    }

    public function stepin ()
    {
        $this->showStoryStep(++ $this->pointer);
    }

    public function stepover ()
    {
        $currentTreeLevel = $this->steps[$this->pointer]['treeLevel'];
        while (($level = $this->steps[++ $this->pointer]['treeLevel'])) {
            if ($level <= $currentTreeLevel)
                break;
        }
        $this->showStoryStep($this->pointer);
    }

    public function stepreturn ()
    {
        $currentTreeLevel = $this->steps[$this->pointer]['treeLevel'];
        while (($level = $this->steps[++ $this->pointer]['treeLevel'])) {
            if ($level < $currentTreeLevel)
                break;
        }
        $this->showStoryStep($this->pointer);
    }

    public function backin ()
    {
        $this->showStoryStep(-- $this->pointer);
    }

    public function backover ()
    {
        $currentTreeLevel = $this->steps[$this->pointer]['treeLevel'];
        while (($level = $this->steps[-- $this->pointer]['treeLevel'])) {
            if ($level <= $currentTreeLevel)
                break;
        }
        $this->showStoryStep($this->pointer);
    }

    public function backreturn ()
    {
        $currentTreeLevel = $this->steps[$this->pointer]['treeLevel'];
        while (($level = $this->steps[-- $this->pointer]['treeLevel'])) {
            if ($level < $currentTreeLevel)
                break;
        }
        $this->showStoryStep($this->pointer);
    }

    protected function startShowStory ()
    {
        $this->pointer = 1;
        $this->showStoryStep($this->pointer);
    }

    protected function mapTransform($filename)
    {
        foreach($this->listOfMapping as $origin => $destination) {
            if(strstr($filename, $origin)) {
                return str_replace($origin, $destination, $filename);
            }
        }

        return $filename;
    }

    protected function showStoryStep ($step)
    {
        if ($step < 0 || $step > count($this->steps))
            return;

        $filename = $this->mapTransform($this->steps[$step]['filename']);

        if ($this->previousFile['name'] != $filename) {
            $this->previousFile['name'] = $filename;

            $lang_mgr = new GtkSourceLanguagesManager();
            $lang = $lang_mgr->get_language_from_mime_type("application/x-php");
            $buffer = GtkSourceBuffer::new_with_language($lang);
            $buffer->set_highlight(1);
            $view = $this->glade->get_object('sourcecode');
            $view->set_buffer($buffer);


            $fileArray = @file($filename);

            $this->previousFile['fileArray'] = $fileArray;

            if (!is_array($fileArray)) {
                $buffer->set_text("");
            } else {
                $buffer->set_text(implode("", $fileArray));
            }

            $view->set_show_line_numbers(1);

            $tagTable = $buffer->get_tag_table();
            $blueTag = new GtkTextTag('colorLine');
            $blueTag->set_property('background', "#f2e911");
            $tagTable->add($blueTag);
        } else {

            $fileArray = $this->previousFile['fileArray'];

            $buffer = $this->glade->get_object('sourcecode')->get_buffer();

            $tagTable = $buffer->get_tag_table();
            $blueTag = $tagTable->lookup('colorLine');

            $buffer->remove_tag($blueTag, $buffer->get_start_iter(), $buffer->get_end_iter());
        }

        $this->glade->get_object('window1')
            ->set_title($this->steps[$step]['filename']);

        $this->currentFile = $fileArray;

        $position = $buffer->get_iter_at_line($this->steps[$step]['line']-1);

        $buffer->place_cursor($position);

        $start = $position;
        $length = strlen($fileArray[$this->steps[$step]['line']-1])-1;

        //If couldn't find the file...
        if($length<0)
            $length = 0;

        $end = $buffer->get_iter_at_line_offset($this->steps[$step]['line']-1, $length);

        $buffer->apply_tag($blueTag, $start, $end);

        $mark = $buffer->create_mark('active_line', $start, false);

        $this->glade->get_object('sourcecode')
            ->scroll_to_mark($mark, 0.4);

        $buffer = $this->glade->get_object('memoryusage')
            ->get_buffer();

        $buffer->set_text($this->steps[$step]['memoryUsage'] . ' -> ' . $this->steps[$step]['memoryDelta']);

        $buffer = $this->glade->get_object('time')
            ->get_buffer();

        $buffer->set_text($this->steps[$step]['timeLaps']);

        $buffer = $this->glade->get_object('currentinstruction')
            ->get_buffer();

        $buffer->set_text($this->steps[$step]['function']);

        $buffer = $this->glade->get_object('returncurrentinstruction')
            ->get_buffer();

        $buffer->set_text($this->steps[$step]['returnValue']);

        $this->glade->get_object('totalsteps')
            ->set_text($step . '/' . count($this->steps));

        $stackDetails = $this->generateStepStackTrace($step);

        $buffer = $this->glade->get_object('stacktrace')
            ->get_buffer();

        $tagTable = $buffer->get_tag_table();

        $linkTag = $tagTable->lookup('link');

        if ($linkTag === NULL) {
            $linkTag = new GtkTextTag('link');
            $linkTag->set_property('foreground', "#0000ff");
            $linkTag->set_property('underline', Pango::UNDERLINE_SINGLE);
            $tagTable->add($linkTag);
            $linkTag->connect('event',
                array( &$this, "onTagLinkEvent"));
        } else {
            $buffer->remove_tag($linkTag, $buffer->get_start_iter(), $buffer->get_end_iter());
        }

        $buffer->set_text($stackDetails['buffer']);

        foreach($stackDetails['linksInformation'] as $link) {
            $buffer->apply_tag($linkTag, $buffer->get_iter_at_offset($link['startOffset']), $buffer->get_iter_at_offset($link['endOffset']));
        }

        $this->stackLinksInfo['url'] = $stackDetails['linksInformation'];
        $this->stackLinksInfo['tag'] = $linkTag;
    }

    public function onTagLinkEvent($textTag, $gtkObject, $event, $textIter)
    {
        if ($event->type == Gdk::BUTTON_RELEASE && $textIter->has_tag($this->stackLinksInfo['tag'])) {
            $step = $this->stackLinksInfo['url'][$textIter->get_line()]['step'];
            $this->showStoryStep($step);
            $this->pointer = $step;
        }
    }

    public function stackLinkCursorChange($view, $event)
    {
        $bufferLocation = $view->window_to_buffer_coords
        (Gtk::TEXT_WINDOW_TEXT, $event->x, $event->y);
        $textIter = $view->get_iter_at_location(
            $bufferLocation[0], $bufferLocation[1]);
        if ($textIter==null || $this->stackLinksInfo['tag'] === null) return;

        if ($textIter->has_tag($this->stackLinksInfo['tag'])) {
            $view->get_window(Gtk::TEXT_WINDOW_TEXT)->set_cursor($this->cursors['link']);
        } else {
            $view->get_window(Gtk::TEXT_WINDOW_TEXT)->set_cursor($this->cursors['textView']);
        }
    }

    public function jump ()
    {
        $step = $this->glade->get_object('jumptostep')
            ->get_text();

        if ($step < 0 || $step > count($this->steps) || ! is_numeric($step) || $step == '')
            return;

        if ($step > count($this->steps)) {
            $step = count($this->steps);
            $step = $this->glade->get_object('jumptostep')
                ->set_text(count($this->steps));
        }

        $this->showStoryStep($step);
        $this->pointer = $step;
    }

    protected function startProgressBar()
    {
        $dialog = new GtkDialog('Work in progress...',
            null, Gtk::DIALOG_MODAL);
        $top_area = $dialog->vbox;
        $top_area->pack_start(new GtkLabel(
            'Please hold on while processing data...'));
        $this->progressBar = new GtkProgressBar();
        $this->progressBar->set_orientation(Gtk::PROGRESS_LEFT_TO_RIGHT);
        $top_area->pack_start($this->progressBar, 0, 0);
        $dialog->set_has_separator(false);
        $dialog->show_all();
        $this->dialog = $dialog;

        $dialog->connect('delete-event',
            array( &$this, "onDeleteEvent"));

        while (Gtk::events_pending()) {Gtk::main_iteration();}
    }

    // function that is called when user closes the progress bar dialog
    public function onDeleteEvent($widget, $event) {
        $this->dialog->destroy();

        return true;
    }

    protected function stopProgressBar()
    {
        $this->dialog->destroy();
    }

    protected function updateProgressBar()
    {
        $this->progressBar->set_fraction($this->currentProgress);
        $this->progressBar->set_text(
            number_format($this->currentProgress*100, 0).'% Complete');

        do {Gtk::main_iteration();} while (Gtk::events_pending());
    }

    protected function generateStepStackTrace($stepIndex)
    {
        $stackTrace = "";
        $functionCount = 0;
        $links = array();

        $treeLevel = $this->steps[$stepIndex]['treeLevel'];

        for ($i = $stepIndex - 1; $i > 0; $i --) {
            $subTreeLevel = $this->steps[$i]['treeLevel'];
            if ($subTreeLevel < $treeLevel) {
                $link = array();
                $treeLevel = $subTreeLevel;
                $stackTrace .= "#" . $functionCount . "  " . substr($this->steps[$i]['function'], 0, strpos($this->steps[$i]['function'], '(')) . "() called at [";
                $link['startOffset'] = strlen($stackTrace);
                $subStackTrace = /*$i . ":" . */$this->steps[$i]['filename'] . ":" . $this->steps[$i]['line'] . "]\n";
                $link['endOffset'] = $link['startOffset'] + strlen($subStackTrace) - 2;
                $link['step'] = $i;
                $stackTrace .= $subStackTrace;
                $functionCount++;
                $links[] = $link;
            }
        }

        return array('buffer' => $stackTrace, 'linksInformation' => $links);
    }

    public function displayStats()
    {
        $window = new GtkWindow();
        $window->set_size_request(1024, 768);
        //$window->connect_simple('destroy', array('Gtk','main_quit'));
        $window->add($vbox = new GtkVBox());

        // display title
        $title = new GtkLabel("Trace file statistics by Derick\n".
            "             http://xdebug.org");
        $title->modify_font(new PangoFontDescription("Times New Roman Italic 10"));
        $title->modify_fg(Gtk::STATE_NORMAL, GdkColor::parse("#0000ff"));
        $title->set_size_request(-1, 40);
        $vbox->pack_start($title, 0, 0);
        $vbox->pack_start(new GtkLabel(), 0, 0);
        $entry = new GtkEntry();
        //$entry->set_size_request(50);
        $entry->connect('changed', array(&$this, 'statsViewFuncFilter2'));
        $hbox = new GtkHBox();
        $hbox->pack_start(new GtkLabel("Filter on function name (regex): "), 0, 0);
        $hbox->pack_start($entry, 0, 0);
        $vbox->pack_start($hbox, 0, 0);

        $data = $this->getFunctions();

        $view = $this->displayTable($vbox, $data);

        $view->set_enable_search(true);
        $view->set_search_column(0);
        $view->set_search_equal_func(array(&$this, 'statsViewFuncFilter'));

        $this->filterModels['entry'] = $entry;

        $window->show_all();
    }

    public function statsViewFuncFilter2($data)
    {
        $key = $this->filterModels['entry']->get_text();

        $key = str_replace('\\', '\\\\', $key);

        $n = $this->filterModels['store']->iter_n_children(NULL);
        for($i=0; $i<$n; ++$i) {
            $iter = $this->filterModels['store']->get_iter($i);
            $val = $this->filterModels['store']->get_value($iter, 0);
            $this->filterModels['store']->set($iter, 8, 1);
            if (@preg_match("|$key|i", $val)) {
                $this->filterModels['store']->set($iter, 8, 1);
            } else {
                $this->filterModels['store']->set($iter, 8, 0);
            }
        }
    }

    public function statsViewFuncFilter($model, $column, $key, $iter)
    {
        $val = $model->get_value($iter, $column);
        $val = strip_tags($val);
        if (preg_match("|$key|i", $val)) {
            return false;
        } else {
            return true;
        }
    }

    protected function displayTable($vbox, $data) {

        // Set up a scroll window
        $scrolled_win = new GtkScrolledWindow();
        $scrolled_win->set_policy( Gtk::POLICY_AUTOMATIC,
            Gtk::POLICY_AUTOMATIC);
        $vbox->pack_start($scrolled_win);

        // Creates the list store
        $model = new GtkListStore(GObject::TYPE_STRING, GObject::TYPE_LONG,
            GObject::TYPE_DOUBLE, GObject::TYPE_LONG, GObject::TYPE_DOUBLE, GObject::TYPE_LONG, GObject::TYPE_DOUBLE, GObject::TYPE_LONG, GObject::TYPE_BOOLEAN);

        $this->filterModels['store'] = $model;

        $fieldHeader = array('Function name', 'calls', 'time inclusive', 'memory inclusive', 'time children', 'memory children', 'time own', 'memory own');
        $fieldJustification = array(0.0,       0.5,        1.0,            1.0,                 1.0,            1.0,                1.0,          1.0);

        $modelFilter = new GtkTreeModelFilter($model);
        $modelFilter->set_visible_column(8);

        $modelSort = new GtkTreeModelSort($modelFilter);
        $modelSort->set_sort_column_id(1, Gtk::SORT_DESCENDING);

        // Creates the view to display the list store
        $view = new GtkTreeView($modelSort);

        $scrolled_win->add($view);

        // Creates the columns
        for ($col=0; $col<count($fieldHeader); ++$col) {
            $cellRenderer = new GtkCellRendererText();
            $cellRenderer->set_property("xalign", $fieldJustification[$col]);
            $column = new GtkTreeViewColumn($fieldHeader[$col],
                $cellRenderer, 'text', $col);

            $column->set_resizable(true);

            if ($col == 0) {
                $column->set_fixed_width(400);
                $column->set_max_width(-1);
                $column->set_min_width(50);
                $column->set_sizing(Gtk::TREE_VIEW_COLUMN_FIXED);
            }

            $column->set_expand(true);

            $column->set_alignment($fieldJustification[$col]);
            $column->set_sort_column_id($col);

            // set the header font and color
            $label = new GtkLabel($fieldHeader[$col]);
            $label->modify_font(new PangoFontDescription("Arial Bold"));
            $label->modify_fg(Gtk::STATE_NORMAL, GdkColor::parse("#0000FF"));
            $column->set_widget($label);
            $label->show();

            // setup self-defined function to display alternate row color
            $column->set_cell_data_func($cellRenderer, array(&$this, "formatCol"));
            $view->append_column($column);
        }

        // pupulates the data
        for ($row=0; $row<count($data); ++$row) {
            $values = array();
            for ($col=0; $col<count($data[$row]); ++$col) {
                $values[] = $data[$row][$col];
            }
            $model->append($values);
        }

        return $view;
    }

    // self-defined function to display alternate row color
    public function formatCol($column, $cell, $model, $iter) {
        $path = $model->get_path($iter);
        $rowNum = $path[0];
        $rowColor = ($rowNum%2==1) ? '#dddddd' : '#ffffff';
        $cell->set_property('cell-background', $rowColor);
    }

    protected function addToFunction( $function, $time, $memory, $nestedTime, $nestedMemory )
    {
        if ( !isset( $this->functions[$function] ) )
        {
            $this->functions[$function] = array( 0, 0, 0, 0, 0 );
        }

        $elem = &$this->functions[$function];
        $elem[0]++;

        if ( !in_array( $function, $this->stackFunctions ) ) {
            $elem[1] += $time;
            $elem[2] += $memory;
            $elem[3] += $nestedTime;
            $elem[4] += $nestedMemory;
        }
    }

    protected function getFunctions()
    {
        $result = array();

        if (!is_array($this->functions)) return $result;

        foreach ( $this->functions as $name => $function )
        {
            $result[] = array(
                $name,
                $function[0],
                $function[1],
                $function[2],
                $function[3],
                $function[4],
                $function[1] - $function[3],
                $function[2] - $function[4],
                1
            );
        }

        return $result;
    }

    protected function processLineHumanEntry($buffer, &$steps)
    {
        $res = preg_match('/^\s+([0-9.]+)\s+([0-9]+)\s+([0-9+-]+)(\s+)->\s+(.*)\s(.*):([0-9]+).*$/', $buffer,
            $steps);

        $steps[4] = (strlen($steps[4]) - 3) / 2 + 1;

        $steps[6] = str_replace(DIRECTORY_SEPARATOR, '/', $steps[6]);

        return $res;
    }

    protected function processLineHumanReturn($buffer, &$steps, $padding)
    {
        $res = preg_match('/^(\s+([0-9.]+)\s+([0-9]+)\s+)>=>\s(.*)$/', $buffer, $steps);

        $steps[1] = (strlen($steps[1]) - $padding) / 2 + 1;

//        if (!$res) {
//            //Check if it is last line of the trace file to clean the stats
//            $res2 = preg_match('/^\s+([0-9.]+)\s+([0-9]+)$/', $buffer, $steps2);
//            if ($res2) {
//                $this->addStatsExit(1, array(2 => $steps2[1], 3 => $steps2[2]));
//            }
//        }

        return $res;
    }

    protected function processLineComputerEntry($buffer, &$steps)
    {
        $parts = explode("\t", $buffer);

        if (count($parts) < 5 || $parts[2] != '0') {
            return false;
        }

        $steps[4] = $parts[0];
        $steps[5] = $parts[5] . '(';

        $steps[1] = $parts[3];
        $steps[2] = $parts[4];
        $steps[3] = $steps[2] - $this->steps[count($this->steps)-1]['memoryUsage'];
        if ($steps[3] >= 0)
            $steps[3] = "+" . $steps[3];
        $steps[6] = str_replace(DIRECTORY_SEPARATOR, '/', $parts[8]);
        $steps[7] = $parts[9];

        if ($parts[10] > 0) {
            $steps[5] .= implode(", ", array_slice($parts, 11));
            $steps[5] = substr($steps[5], 0, -1);
        }

        if (!empty($parts[7])) {
            $steps[5] .= $parts[7];
        }

        $steps[5] .= ')';

        return true;

    }

    protected function processLineComputerReturn($buffer, &$steps, &$handle)
    {
        $parts = explode( "\t", $buffer );

        if ( count( $parts ) < 5 || $parts[2] != '1') {
            return false;
        }

        $steps[1] = $parts[0];
        $steps[2] = $parts[3];
        $steps[3] = $parts[4];

        if (($buffer2 = fgets($handle)) !== false) {
            $parts = explode( "\t", $buffer2 );

            if ($parts['2'] == 'R') {
                $steps[4] = $parts[5];
            } else {
                fseek($handle, -1*strlen($buffer2), SEEK_CUR);
            }
        }

        return true;

    }

    protected function processTraceFile ()
    {
        $this->steps = array();

        $handle = @fopen($this->fileName, "r");
        if ($handle) {
            $i = 0;
            $offset = 0;
            $stats = fstat($handle);
            $fileSize = $stats['size'];
            $lastPercent = 0;
            $computerFormat = false;

            $padding = 0;

            if (preg_match("/^Version: /", fgets($handle))) {
                $computerFormat = true;
            }

            while (($buffer = fgets($handle)) !== false) {
                $offset += strlen($buffer);
                $this->currentProgress = $offset / $fileSize;
                $currentPercent = round($this->currentProgress * 100);

                if ($currentPercent > $lastPercent && $currentPercent % 2 == 0) {
                    $lastPercent = $currentPercent;
                    $this->updateProgressBar();
                }

                if ($computerFormat) {
                    $res = $this->processLineComputerEntry($buffer, $steps);
                } else {
                    $res = $this->processLineHumanEntry($buffer, $steps);
                }

                if ($res) {
                    $this->steps[] = array(
                        'timeLaps' => $steps[1],
                        'memoryUsage' => $steps[2],
                        'memoryDelta' => $steps[3],
                        'treeLevel' => $steps[4],
                        'function' => $steps[5],
                        'filename' => $steps[6],
                        'line' => $steps[7],
                        'line2' => $i,
                        'returnValue' => NULL
                    );
                    $this->globalFileNameListInOrder[] = $steps[6];

                    if (!$computerFormat) {
                        /*
                         * Work around in trace_format = 0 to generate proper stats
                         */
                        //Case last function call is deeper depth and still null is stored
                        //in return value which mean the return value was void
                        if ($steps[4] < $this->steps[count($this->steps)-2]['treeLevel'] && $this->steps[count($this->steps)-2]['returnValue'] === NULL) {
                            $this->steps[count($this->steps)-2]['returnValue'] = 'void';
                            //Minimize inaccuracy. When no return value is given by the trace file
                            //the data are missing for the memory and time so I just reuse
                            //the data provided at the entry point...
                            $tmpSteps[2] = $this->steps[count($this->steps)-2]['timeLaps'];
                            $tmpSteps[3] = $this->steps[count($this->steps)-2]['memoryUsage'];
                            $this->addStatsExit($steps[4], $tmpSteps);
                        }

                        //Case same depth function in stack will overwrite previous one so we take this
                        //opportunity to check if a return has been received for the previous one and if
                        //not we just store void and call the add stats exit.
                        if ($this->stack[$steps[4]][5] > 0 && $this->steps[$this->stack[$steps[4]][5]]['returnValue'] === NULL) {
                            //Informed in the debugger that the function return void
                            $this->steps[$this->stack[$steps[4]][5]]['returnValue'] = 'void';
                            //Minimize inaccuracy. When no return value is given by the trace file
                            //the data are missing for the memory and time so I just reuse
                            //the data provided at the entry point...
                            $tmpSteps[2] = $this->steps[$this->stack[$steps[4]][5]]['timeLaps'];
                            $tmpSteps[3] = $this->steps[$this->stack[$steps[4]][5]]['memoryUsage'];
                            $this->addStatsExit($steps[4], $tmpSteps);
                        }
                    }

                    $this->addStatsEnter($steps);
                }

                if ($computerFormat) {
                    $res = $this->processLineComputerReturn($buffer, $retValue, $handle);
                } else {
                    $res = $this->processLineHumanReturn($buffer, $retValue, $padding);
                }

                if ($res) {
                    $otherTreeLevel = $retValue[1];

                    $stepNumber = $this->addStatsExit($otherTreeLevel, $retValue);

                    $this->steps[$stepNumber]['returnValue'] = $retValue[4];
                }

                //Only use in human format
                if ($i == 0) {
                    $padding = strlen($buffer) - strlen(strstr($buffer, '->'));
                }
                $i ++;
            }
            if (! feof($handle)) {
                die("Error: unexpected fgets() fail\n");
            }
            fclose($handle);
        }
    }

    /**
     * @param $otherTreeLevel
     * @param $retValue
     */
    protected function addStatsExit($otherTreeLevel, $retValue)
    {
        list($funcName, $prevTime, $prevMem, $nestedTime, $nestedMemory, $stepNumber) = $this->stack[$otherTreeLevel];

        // collapse data onto functions array
        $time = $retValue[2];
        $memory = $retValue[3];
        $dTime = $time - $prevTime;
        $dMemory = $memory - $prevMem;

        $this->stack[$otherTreeLevel - 1][3] += $dTime;
        $this->stack[$otherTreeLevel - 1][4] += $dMemory;

        array_pop($this->stackFunctions);

        $this->addToFunction($funcName, $dTime, $dMemory, $nestedTime, $nestedMemory);

        return $stepNumber;
    }

    /**
     * @param $steps
     */
    protected function addStatsEnter($steps)
    {
        $depth = $steps[4];
        $time = $steps[1];
        $memory = $steps[2];
        $funcName = substr($steps[5], 0, strpos($steps[5], '('));

        $this->stack[$depth] = array($funcName, $time, $memory, 0, 0, count($this->steps)-1);

        array_push($this->stackFunctions, $funcName);
    }
}

$gtkBuilder->connect_signals_instance(new GTK_XdTrace($gtkBuilder));

$gtkBuilder->get_object('window1')
    ->connect_simple('destroy', array(
        'Gtk',
        'main_quit'
    ));

// Start the main loop
Gtk::main();

?>
