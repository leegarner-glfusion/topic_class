<?php
/**
*   Topic class, used to track the current topic.
*
*   Usage:  Topic::setCurrent(tid)      set the current topic
*           Topic::Current()            retrieve the current topic object
*           Topic::Current()->varname   get "varname" for the current topic
*           Topic::Current(varname, default)    get "varname", or default
*           Topic::Clear()              unset the current topic
*           Topic::Get(tid)             get a single topic object
*           Topic::Get(tid, fld, default) get a topic's field, default if not found
*           Topic::All()                get an array of all topic objects
*           Topic::Access(tid)          check the current user's topic access
*           Topic::isEmpty()            check if the current topic is set
*           Topic::currentID(def_tid)   get the current TID, or "def_tid"
*           Topic::archiveID()          get the archive TID
*           Topic::defaultID()          get the default TID
*           Topic::optionList()         create the <option></option> elements
*
*   @author     Lee Garner <lee@leegarner.com>
*   @copyright  Copyright (c) 2017 Lee Garner <lee@leegarner.com>
*   @package    glfusion
*   @version    0.0.1
*   @license    http://opensource.org/licenses/gpl-2.0.php
*               GNU Public License v2 or later
*   @filesource
*/
class Topic
{
    /**
    *   Holder for the current topic ID
    *   @var string */
    private static $current = NULL;

    /**
    *   Array holder for all topic objects
    *   Not sure if this is needed, but need to store all topics somewhere
    *   and if archiveTID() and optionList() get called for the same page load
    *   it will save a query.
    *   @var array */
    private static $all = NULL;

    /**
    *   Cache of topic objects.
    *   Populated by Get() only for requested objects, e.g. the index page.
    *   @var array */
    private static $cache = array();

    /**
    *   Default base image path/URL.
    *   @var string */
    private static $def_imageurl = '/images/topics/';

    /**
    *   Default value for limitnews
    *   @var integer */
    private static $def_limitnews = 10;

    /**
    *   Default value for sort_by
    *   @var string */
    private static $def_sort_by = 'date';

    /**
    *   Default value for sort_dir
    *   @var string */
    private static $def_sort_dir = 'DESC';

    /**
    *   Array holder for fields in the instantiated topic.
    *   @var array */
    private $properties = array();

    /**
    *   Original TID of the topic.
    *   If this is not empty, then the topic is being edited. Otherwise this
    *   is a new topic.
    *   @var string */
    private $old_tid = '';

    /**
    *   Flag to indicate a new topic, vs. one being edited.
    *   @var boolean;
    */
    private $isNew;

    /**
    *   Get a value from the current topic, or the current topic object
    *
    *   @uses   self::currentID()   To get the current topic ID
    *   @uses   self::Get()         To get a specific topic field value
    *   @param  string  $key        Name of value, if requested
    *   @param  mixed   $default    Default value, NULL to use class default
    *   @return mixed               Value of field, or object if no field.
    */
    public static function Current($key = NULL, $default = NULL)
    {
        return self::Get(self::$current, $key, $default);
    }


    /**
    *   Sets or resets the current topic, and returns the current topic object
    *
    *   @uses   self::Get()
    *   @param  string  $tid    Topic ID to set
    *   @return object          Current topic object
    */
    public static function setCurrent($tid)
    {
        // First try to get the requested topic. Only if valid is the current
        // topic actually changed.
        $obj = self::Get($tid);
        if ($obj !== NULL) {
            self::$current = $tid;
        }
        return self::Current();
    }


    /**
    *   Get the current topic ID, or the supplied default value if empty.
    *
    *   @uses   self::Get()
    *   @uses   self::isEmpty()
    *   @param  string  $def_tid    Default return value if no current topic
    *   @return string              Current topic ID, or supplied return value
    */
    public static function currentID($def_tid = '')
    {
        return self::isEmpty() ? $def_tid : self::Current()->tid;
    }


    /**
    *   Get the archive topic ID
    *   Returns the first topic ID with the archive flag set. There should
    *   be only one.
    *
    *   @return string      ID of designated archive topic
    */
    public static function archiveID()
    {
        global $_TABLES;

        // Check the topic cache first
        foreach (self::$cache as $tid=>$obj) {
            if ($obj->archive_flag == 1) return $tid;
        }
        // Not found in cache, read from DB
        return DB_getItem($_TABLES['topics'], 'tid', 'archive_flag=1');
    }


    /**
    *   Get the default topic ID
    *   Returns the first topic ID with the is_default flag set. There should
    *   be only one.
    *
    *   @return string      ID of designated archive topic
    */
    public static function defaultID()
    {
        global $_TABLES;

        // Check the topic cache first
        foreach (self::$cache as $tid=>$obj) {
            if ($obj->is_default == 1) return $tid;
        }
        // Not found in cache, read from DB
        return DB_getItem($_TABLES['topics'], 'tid', 'is_default=1');
    }


    /**
    *   Get a selection list of topics.
    *
    *   @uses   self::All()         To get all available topics
    *   @param  string  $selected   Selected topic ID, if any
    *   @param  string  $fld        Name of field to display in list
    *   @param  integer $access     Access level required, default "2" (read)
    *   @return string              Option tags for selection list
    */
    public static function optionList($selected = '', $fld = 'topic', $access = 2)
    {
        $opts = array();
        foreach (self::All() as $tid=>$obj) {
            if (self::Access($tid) < $access) continue;
            $sel = $tid == $selected ? ' selected="selected"' : '';
            $opts[] .= "<option value=\"$tid\"$sel>" .
                    htmlspecialchars($obj->$fld) .
                    '</option>';
        }
        return implode(LB, $opts);
    }


    /**
    *   Get all topic records.
    *   First load the static $all variable with all objects, then return the
    *   array.
    *
    *   @return array   All topic records as objects
    */
    public static function All()
    {
        global $_TABLES;

        if (self::$all === NULL) {
            $sql = "SELECT * FROM {$_TABLES['topics']} ORDER BY sortnum ASC";
            $res = DB_query($sql, 1);
            if (DB_error()) {
                COM_errorLog(__CLASS__ . '::' . __FUNCTION__ . ': Unable to read topics table');
            } elseif (DB_numRows($res) == 0) {
                COM_errorLog(__CLASS__ . '::' . __FUNCTION__ . ': No topics found');
            } else {
                while ($A = DB_fetchArray($res, false)) {
                    self::$all[$A['tid']] = new self($A);
                }
            }
        }
        return self::$all;
    }


    /**
    *   Clear the current topic.
    */
    public static function Clear()
    {
        self::setCurrent(NULL);
    }


    /**
    *   Determine if the current topic is *not* set
    *
    *   @return boolean     True if not set, False if set
    */
    public static function isEmpty()
    {
        return (self::$current === NULL) ? true : false;
    }


    /**
    *   Constructor.
    *   Reads the topic into the object variables.
    *   Does not set the current topic.
    *
    *   @param  mixed   $tid    Topic ID, or complete topic record from DB
    */
    public function __construct($tid = '')
    {
        // Set default for new topic objects. Will be overridden by setVars()
        $this->imageurl = self::$def_imageurl;
        $this->limitnews = self::$def_limitnews;
        $this->sort_by = self::$def_sort_by;
        $this->sort_dir = self::$def_sort_dir;
        $this->isNew = true;

        if (!empty($tid)) {
            if (is_array($tid)) {
                // A DB record passed in as an array
                $this->setVars($tid, true);
            } else {
                // A single topic ID passed in as a string
                // Gets the info from self::Get() to ensure properties array
                // is populated.
                $obj = self::Get($tid);
                if ($obj) {
                    $this->isNew = false;
                    $this->setVars($obj->properties, true);
                }
            }
        }
        // Else, this is an empty object to populate from a form.
    }


    /**
    *   Sets all variables from an array into object members
    *
    *   @param  array   $A      Array of key-value pairs
    *   @param  boolean $fromDB True if reading from DB, False if from a form
    */
    public function setVars($A, $fromDB=false)
    {
        if ($fromDB) {
            foreach ($A as $key=>$value) {
                $this->$key = $value;
            }
        } else {
            $this->old_tid   = $A['old_tid'];
            $this->tid      = $A['tid'];
            $this->topic    = $A['topic'];
            $this->description = $A['description'];
            $this->imageurl = $A['imageurl'];
            $this->sortnum  = $A['sortnum'];
            $this->limitnews = $A['limitnews'];
            $this->is_default = isset($A['is_default']) ? 1 : 0;
            $this->archive_flag = isset($A['archive_flag']) ? 1 : 0;
            $this->sort_by  = $A['sort_by'];
            $this->sort_dir = $A['sort_dir'];
            $this->owner_id = $A['owner_id'];
            $this->group_id = $A['group_id'];

            // Get array values from form and convert to ints.
            list($this->perm_owner, $this->perm_group, $this->perm_members, $this->perm_anon) =
                SEC_getPermissionValues($A['perm_owner'], $A['perm_group'], $A['perm_members'], $A['perm_anon']);
        }
    }


    /**
    *   Set a key-value pair in the properties array
    *
    *   @param  string  $key    Property name
    *   @param  mixed   $value  Value of property
    */
    public function __set($key, $value)
    {
        switch ($key) {
        case 'owner_id':
        case 'group_id':
        case 'perm_owner':
        case 'perm_group':
        case 'perm_members':
        case 'perm_anon':
        case 'limitnews':
        case 'sortnum':
            $this->properties[$key] = (int)$value;
            break;
        case 'is_default':
        case 'archive_flag':
            $this->properties[$key] = $value == 0 ? 0 : 1;
            break;
        case 'sort_dir':
            $this->properties[$key] = $value == 'ASC' ? 'ASC' : 'DESC';
            break;
        case 'tid':
        case 'old_tid':
            $this->properties[$key] = COM_sanitizeID($value, false);
            break;
        default:
            $this->properties[$key] = $value;
            break;
        }
    }


    /**
    *   Get a property by name
    *
    *   @param  string  $key    Name of property to return
    *   @return mixed       Value of property, NULL if undefined
    */
    public function __get($key)
    {
        return isset($this->properties[$key]) ? $this->properties[$key] : NULL;
    }


    /**
    *   Wrapper to get a value and return a default for empty topics.
    *
    *   @param  string  $key        Name of value
    *   @param  mixed   $default    Default value, NULL to use class default
    *   @return mixed               Value of field
    */
    public static function Get($tid, $key = NULL, $default = NULL)
    {
        global $_TABLES;

        $obj = NULL;
        $tid = COM_sanitizeID($tid, false);
        if (empty($tid)) return NULL;

        // Get the topic object, first checking cache then the DB
        if (isset(self::$cache[$tid])) {
            $obj = self::$cache[$tid];
        } else {    // Attempt to read the topic
            $sql = "SELECT * FROM {$_TABLES['topics']} WHERE tid = '$tid'";
            $res = DB_query($sql, 1);
            if (DB_error()) {
                COM_errorLog(__CLASS__ . '::' . __FUNCTION__ . ': Unable to read topics table');
                return NULL;
            } elseif (DB_numRows($res) == 0) {
                COM_errorLog(__CLASS__ . '::' . __FUNCTION__ . ": Topic $tid not found");
                return NULL;;
            } else {
                $A = DB_fetchArray($res, false);
                self::$cache[$A['tid']] = new self($A);
                $obj = self::$cache[$A['tid']];
            }
        }

        if ($obj === NULL) {
            if ($key !== NULL) {
                // Bad topic, key requested, return default or NULL if none
                if ($default === NULL) {
                    // No default supplied, use some standard ones
                    switch ($key) {
                    case 'limitnews':
                        $default = self::$def_limitnews;
                        break;
                    case 'sort_by':
                        $default = self::$def_sort_by;
                        break;
                    case 'sort_dir':
                        $default = self::$def_sort_dir;
                        break;
                    }
                }
                return $default;
            } else {
                // No value requested, return null object
                return NULL;
            }
        } elseif ($key !== NULL) {
            // Good topic, key requested, return the value
            return $obj->$key;
        } else {
            // Good topic, no key requested, return the topic object
            return $obj;
        }
    }


    /**
    *   Determine the current user's access to a specified topic
    *
    *   @param  integer $tid        Topic to check
    *   @return integer Access level (0=none, 2=read, 3=write)
    */
    public static function Access($tid)
    {
        global $_GROUPS;

        if (SEC_inGroup('Topic Admin')) {
            // Admin always has full access
            return 3;
        }

        $t = self::Get($tid);    // shorthand for readability
        if ($t !== NULL) {
            return SEC_hasAccess(
                $t->owner_id, $t->group_id,
                $t->perm_owner, $t->perm_group, $t->perm_members, $t->perm_anon
            );
        } else {
            // Topic not defined, return "no access"
            return 0;
        }
    }


    /**
    *   Display the edit form for a topic.
    *
    *   @param  array   $A      Array of data in case form is redisplayed
    *   @return string          HTML for topic form
    */
    public function Edit($A = array())
    {
        global $_CONF, $_TABLES, $LANG27;;

        if (!SEC_hasRights('topic.edit')) COM_404();

        // Load previous field values if array is not empty
        if (!empty($A)) $this->setVars($A, false);
        $access = 3;
        $assoc_stories_published = 0;
        $assoc_stories_draft = 0;
        $assoc_images = 0;
        $assoc_comments = 0;
        $assoc_trackbacks = 0;

        if (!$this->isNew) {
            // Find what is associated with this topic
            $assoc_blocks = DB_count($_TABLES['blocks'], 'tid', $this->tid);
            $assoc_feeds = DB_count($_TABLES['syndication'], 'topic', $this->tid);
            $assoc_stories_submitted = DB_count($_TABLES['storysubmission'], 'tid', $this->tid);

            $res = DB_query("SELECT sid, draft_flag FROM {$_TABLES['stories']} WHERE tid = '{$this->tid}'");
            $total_assoc_stories = DB_numRows($res);
            if ($total_assoc_stories > 0) {
                while ($S = DB_fetchArray($res, false)) {
                    if ($S['draft_flag'] == 0) {
                        $assoc_stories_published++;
                    } else {
                        $assoc_stories_draft++;
                    }
                    $assoc_images += DB_count($_TABLES['article_images'], 'ai_sid', $S['sid']);
                    $assoc_comments += DB_count($_TABLES['comments'], array('sid', 'type'), array($S['sid'], 'article'));
                    $assoc_trackbacks += DB_count($_TABLES['trackback'], array('sid', 'type'), array($S['sid'], 'article'));
                }
            }
        } else {
            $assoc_blocks = 0;
            $assoc_feeds = 0;
            $assoc_stories_submitted = 0;
            $total_assoc_stories = 0;
        }

        $assoc_stories = (($assoc_stories_published > 0) OR
                        ($assoc_stories_draft > 0) OR
                        ($assoc_stories_submitted > 0) OR
                        ($assoc_images > 0) OR
                        ($assoc_comments > 0) OR
                        ($assoc_trackbacks > 0));

        if ($this->imageurl == '') $this->imageurl = self::$def_imageurl;
        $T = new Template($_CONF['path_layout'] . 'admin/topic');
        $T->set_file('editor', 'topiceditor_new.thtml');
        $T->set_var(array(
            'old_tid'   => $this->tid,
            'tid'       => $this->tid,
            'topic'     => $this->topic,
            'description' => $this->description,
            'limitnews' => $this->limitnews,
            'default_chk' => $this->is_default ? 'checked="checked"' : '',
            'archive_chk' => $this->archive_flag? 'checked="checked"' : '',
            'sort_opt' . $this->sort_by => 'selected="selected"',
            'sort_dir_' . strtoupper($this->sort_dir) => 'selected="selected"',
            'imageurl'  => $this->imageurl,
            'owner_dropdown' => COM_buildOwnerList('owner_id', $this->owner_id),
            'group_dropdown' => SEC_getGroupDropdown($this->group_id, $access),
            'assoc_stories_published' => $assoc_stories_published,
            'published_story_admin_link' => COM_createLink($LANG27[52], $_CONF['site_admin_url'] . '/story.php?tid=' . $this->tid),
            'assoc_stories_draft' => $assoc_stories_draft,
            'draft_story_admin_link' => COM_createLink($LANG27[52], $_CONF['site_admin_url'] . '/story.php?tid=' . $this->tid),
            'assoc_stories_submitted' => $assoc_stories_submitted,
            'moderation_link' => COM_createLink($LANG27[53], $_CONF['site_admin_url'] . '/moderation.php'),
            'assoc_images'  => $assoc_images,
            'assoc_comments' => $assoc_comments,
            'assoc_trackbacks' => $assoc_trackbacks,
            'assoc_blocks'  => $assoc_blocks,
            'block_admin_link' => COM_createLink($LANG27[54], $_CONF['site_admin_url'] . '/block.php'),
            'assoc_feeds'   => $assoc_feeds,
            'syndication_admin_link' => COM_createLink($LANG27[55], $_CONF['site_admin_url'] . '/syndication.php'),
            'icon_max_width'    => $_CONF['max_topicicon_width'],
            'icon_max_height'   => $_CONF['max_topicicon_height'],
            'default_limit' => $_CONF['limitnews'],
            'permissions_editor' => SEC_getPermissionsHTML($this->perm_owner,
                    $this->perm_group, $this->perm_members, $this->perm_anon),
            'delete_option' => $this->isNew ? false : true,
        ) );
        if (strtolower(substr(strrchr($this->imageurl, '.'), 1)) == 'svg' ||
            @getimagesize($_CONF['path_html'].$this->imageurl) !== false)  {
            $T->set_var('topicimage', $_CONF['site_url'] . $this->imageurl);
        }

        if (($assoc_blocks > 0) OR ($assoc_feeds > 0) OR ($assoc_stories)) {
            $T->set_var('lang_assoc_objects', $LANG27[43]);
        }

        $T->set_block('editor', 'sort_selection', 'sortsel');
        foreach (self::All() as $tid=>$obj) {
            if ($obj->tid == $this->tid) continue;
            $sel = ($this->sortnum - 10) == $obj->sortnum ? 'selected="selected"' : '';
            $T->set_var(array(
                'sortnum' => $obj->sortnum,
                'sortnum_sel'  => $sel,
                'sortnum_tid'  => $obj->tid,
            ) );
            $T->parse('sortsel', 'sort_selection', true);
        }
        $T->parse('output', 'editor');
        $retval .= $T->finish($T->get_var('output'));
        $retval .= COM_endBlock (COM_getBlockTemplate ('_admin_block', 'footer'));
        return $retval;
    }


    /**
    *   Reorder all topics
    *
    *   @return boolean     True on success, False on SQL error
    */
    public function ReOrder()
    {
        global $_TABLES;

        $sortnum = 10;
        $stepNumber = 10;
        self::$all = NULL;  // To force updating the cache
        foreach (self::All() as $tid=>$obj) {
            if ($obj->sortnum != $sortnum) {  // only update incorrect ones
                $sql = "UPDATE {$_TABLES['topics']}
                    SET sortnum = $sortnum
                    WHERE tid = '{$obj->tid}'";
                DB_query($sql, 1);
                if (DB_error()) {
                    COM_errorLog("Topic::reOrder() SQL error: $sql");
                    return false;
                }
            }
            $sortnum += $stepNumber;
        }
        return true;
    }


    /**
    *   Move a topic up or down in the list.
    *   The order field is incremented by 10, so this adds or subtracts 11
    *   to change the order, then reorders the fields.
    *
    *   @uses   Topic::ReOrder()
    *   @param  string  $tid    Topic to move
    *   @param  string  $dir    Direction to move ('up' or 'down')
    *   @return boolean     True on success, False on error
    */
    public static function Move($tid, $dir)
    {
        global $_CONF, $_TABLES, $LANG21;

        $tid = DB_escapeString($tid);

        switch ($dir) {
        case 'up':
            $sign = '-';
            break;

        case 'down':
            $sign = '+';
            break;

        default:
            // Invalid option, return true but do nothing
            return true;
            break;
        }
        $sql = "UPDATE {$_TABLES['topics']}
                SET sortnum = sortnum $sign 11
                WHERE tid = '$tid'";
        DB_query($sql, 1);
        if (!DB_error()) {
            // Reorder fields to get them separated by 10
            return self::ReOrder();
        } else {
            return false;
        }
    }


    /**
    *   Save the submitted topic information
    *
    *   @param  array   $A  Option all array of values, e.g. $_POST
    *   @return boolean     True on success, False on failure
    */
    public function Save($A = array())
    {
        global $_TABLES;

        // Make sure this is a topic admin
        if (!SEC_hasRights('topic.edit')) COM_404();

        // Typically this will be $_POST
        if (!empty($A)) $this->setVars($A);

        // Set up SQL query depending on whether this is a new or edited
        // topic. Also, check if the TID has changed and is possibly a duplicate.
        $tid_count = DB_count($_TABLES['topics'], 'tid', $this->tid);
        if ($this->isNew) {
            $max_existing_tids = 0;
            $sql1 = "INSERT INTO {$_TABLES['topics']} SET tid = '{$this->tid}', ";
            $sql3 = '';
        } else {
            $max_existing_tids = $this->tid == $this->old_tid ? 1 : 0;
            $sql1 = "UPDATE {$_TABLES['topics']} SET ";
            $sql3 = " WHERE tid = '{$this->old_tid}'";
        }
        if ($tid_count > $max_existing_tids) {
            COM_setMsg("Duplicate TID");
            return false;
        }

        // Upload a new icon if selected
        if (!empty($_FILES['newicon']['name'])) {
            $this->imageurl = $this->uploadIcon();
        }
        // If the image URL is empty, just save the string. Otherwise, if
        // it is false, there was an error during uploadIcon so abort the save
        if ($this->imageurl == self::$def_imageurl) {
            $this->imageurl = '';
        } elseif ($this->imageurl === false) {
            return false;
        }

        // sortnum contains the sort number of the topic to follow, so
        // increment it to sort after that topic.
        $this->sortnum = $this->sortnum + 1;

        // Setting this topic as default, unset all others.
        if ($this->is_default) {
            DB_query("UPDATE {$_TABLES['topics']}
                    SET is_default = 0
                    WHERE is_default = 1");
        }

        $archivetid = self::archiveID();
        if ($this->archive_flag) {
            if ($archivetid != $this->tid) {
                // This is the archive topic, but it wasn't before
                // Update all stories with archive settings
                DB_query("UPDATE {$_TABLES['stories']} SET
                        featured = 0,
                        frontpage = 0,
                        statuscode = " . STORY_ARCHIVE_ON_EXPIRE .
                    " WHERE tid = '{$this->tid}'");
                DB_query("UPDATE {$_TABLES['topics']}
                    SET archive_flag = 0
                    WHERE archive_flag = 1");
            }
        } else {
           if ($archivetid == $this->tid) {
                // This is not the archive topic, but it used to be
                DB_query("UPDATE {$_TABLES['stories']}
                    SET statuscode = 0
                    WHERE tid = '$tid'");
                DB_query("UPDATE {$_TABLES['topics']}
                    SET archive_flag = 0
                    WHERE archive_flag = 1");
            }
        }

        $sql2 = "topic = '" . DB_escapeString($this->topic) . "',
                description = '" . DB_escapeString($this->description) . "',
                imageurl = '" . DB_escapeString($this->imageurl) . "',
                sortnum = '{$this->sortnum}',
                sort_by = '{$this->sort_by}',
                sort_dir = '{$this->sort_dir}',
                limitnews = '{$this->limitnews}',
                is_default = '{$this->is_default}',
                archive_flag = '{$this->archive_flag}',
                owner_id = '{$this->owner_id}',
                group_id = '{$this->group_id}',
                perm_owner = '{$this->perm_owner}',
                perm_group = '{$this->perm_group}',
                perm_members = '{$this->perm_members}',
                perm_anon = '{$this->perm_anon}'";

        DB_query($sql1 . $sql2 . $sql3);
        if (!DB_error()) {
            self::ReOrder();

            // TID has changed and is confirmed OK (not duplicate).
            // Now update all other content items that have the old TID.
            if ($this->tid != $this->old_tid) {
                DB_query("UPDATE {$_TABLES['stories']}
                        SET tid = '{$this->tid}'
                        WHERE tid = '{$this->old_tid}'");
                DB_query("UPDATE {$_TABLES['stories']}
                        SET alternate_tid = '{$this->tid}'
                        WHERE alternate_tid = '{$this->old_tid}'");
                DB_query("UPDATE {$_TABLES['storysubmission']}
                        SET tid = '{$this->tid}'
                        WHERE tid = '{$this->old_tid}'");
                DB_query("UPDATE {$_TABLES['syndication']}
                        SET topic = '{$this->tid}'
                        WHERE topic = '{$this->old_tid}'");
                DB_query("UPDATE {$_TABLES['syndication']}
                        SET header_tid = '{$this->tid}'
                        WHERE header_tid = '{$this->old_tid}'");
                DB_query("UPDATE {$_TABLES['blocks']}
                        SET tid = '{$this->tid}'
                        WHERE tid = '{$this->old_tid}'");
            }

            // update feed(s) and Older Stories block
            COM_rdfUpToDateCheck('article', $this->tid);
            COM_olderStuff();
            CACHE_remove_instance('menu');
            COM_setMessage(13);
            return true;
        } else {
            return false;
        }
    }


    /**
    *   Delete a topic.
    *   Promotes alternate topic to main topic for stories that have one.
    *   Changes blocks and feeds to all topics and disables them.
    *   Deletes stories and submissions in this topic.
    *
    *   @return boolean     True on success, False on failure.
    */
    public function Delete()
    {
        global $_TABLES;

        if (!SEC_hasRights('topic.edit')) COM_404();

        // Don't delete topic blocks - assign them to 'all' and disable them
        $sql = "UPDATE {$_TABLES['blocks']}
                SET tid = 'all', is_enabled = 0
                WHERE tid = '{$this->tid}'";
        DB_query($sql);

        // Same with feeds
        $sql = "UPDATE {$_TABLES['syndication']}
                SET topic = '::all', is_enabled = 0
                WHERE topic = '{$this->tid}'";
        DB_query($sql);

        // Remove any alternate topics
        $sql = "UPDATE {$_TABLES['stories']}
                SET alternate_tid = NULL
                WHERE alternate_tid = '{$this->tid}'";
        DB_query($sql);

        // Promote stories with a different alt topic
        $sql = "SELECT sid,alternate_tid
               FROM {$_TABLES['stories']}
               WHERE tid = '{$this->tid}'
               AND (alternate_tid IS NOT NULL || alternate_tid != '')";
        $result = DB_query($sql);
        while ($A = DB_fetchArray($result, false)) {
            $sql = "UPDATE {$_TABLES['stories']}
                    SET tid='".DB_escapeString($A['alternate_tid'])."', alternate_tid=NULL
                    WHERE sid='".DB_escapeString($A['sid'])."'";
            DB_query($sql);
        }

        // Delete stories and related comments, trackbacks, images in this topic
        $sql = "SELECT sid FROM {$_TABLES['stories']}
                    WHERE tid = '{$this->tid}'";
        $result = DB_query($sql);
        while ($A = DB_fetchArray($result, false)) {
            STORY_removeStory($A['sid']);
        }

        // Delete submissions and this topic
        DB_delete($_TABLES['storysubmission'], 'tid', $this->tid);
        DB_delete($_TABLES['topics'], 'tid', $this->tid);

        // Update feed(s) and Older Stories block
        COM_rdfUpToDateCheck('article');
        COM_olderStuff();
        CACHE_remove_instance('menu');
        COM_setMessage(14);
        return true;
    }


    /**
    *   Upload new topic icon, replaces previous icon if one exists
    *
    * @param    string  tid     ID of topic to prepend to filename
    * @return   mixed   filename of new photo (empty = no new photo), or false
    */
    private function uploadIcon()
    {
        global $_CONF, $LANG27;

        $upload = new upload();
        if (!empty($_CONF['image_lib'])) {
            $upload->setAutomaticResize(true);
            if (isset($_CONF['debug_image_upload']) &&
                    $_CONF['debug_image_upload']) {
                $upload->setLogFile($_CONF['path'] . 'logs/error.log');
                $upload->setDebug(true);
            }
        }
        $upload->setAllowedMimeTypes(array(
            'image/gif'   => '.gif',
            'image/jpeg'  => '.jpg,.jpeg',
            'image/pjpeg' => '.jpg,.jpeg',
            'image/x-png' => '.png',
            'image/png'   => '.png',
            'image/svg+xml' => '.svg',
        ) );
        if (!$upload->setPath($_CONF['path_images'] . 'topics')) {
            COM_setMsg($upload->printErrors(false), 'error', true, $LANG27[29]);
            return false;
        }
        $upload->setFieldName('newicon');

        // Create the target filename
        $p = pathinfo($_FILES['newicon']['name']);
        if (isset($p['extension'])) {
            $filename = 'topic_' . $this->tid . '.' . $p['extension'];
        } else {
            $filename = '';
        }

        // do the upload
        if (!empty($filename)) {
            $upload->setFileNames($filename);
            $upload->setPerms('0644');
            if (    ($_CONF['max_topicicon_width'] > 0) &&
                    ($_CONF['max_topicicon_height'] > 0)    ) {
                $upload->setMaxDimensions($_CONF['max_topicicon_width'],
                                       $_CONF['max_topicicon_height']);
            } else {
                $upload->setMaxDimensions($_CONF['max_image_width'],
                                       $_CONF['max_image_height']);
            }
            if ($_CONF['max_topicicon_size'] > 0) {
                $upload->setMaxFileSize($_CONF['max_topicicon_size']);
            } else {
                $upload->setMaxFileSize($_CONF['max_image_size']);
            }

            $upload->uploadFiles();
            if ($upload->areErrors()) {
                COM_setMsg($upload->printErrors(false), 'error', true, $LANG27[29]);
                return false;
            }
            $filename = self::$def_imageurl . $filename;
        }
        return $filename;
    }

}

?>
