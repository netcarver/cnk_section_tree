<?php

$plugin['name'] = 'cnk_section_tree';
$plugin['version'] = '0.3.9';
$plugin['author'] = 'Christian Nowak';
$plugin['author_uri'] = 'http://www.cnowak.de';
$plugin['description'] = 'Section Tree';
$plugin['type'] = '1';

@include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
if(@txpinterface == 'admin') 
{
	/*
	1 => gTxt('publisher'),
	2 => gTxt('managing_editor'),
	3 => gTxt('copy_editor'),
	4 => gTxt('staff_writer'),
	5 => gTxt('freelancer'),
	6 => gTxt('designer'),
	0 => gTxt('none')
	*/

	add_privs('cnk_section_tree','1,2');
	register_tab('extensions', 'cnk_section_tree', "section tree");
	register_callback('cnk_section_tree', 'cnk_section_tree');
	register_callback('cnk_section_list', 'section', '', 1);
	register_callback('cnk_article_view', 'article', '', 1);
	register_callback('cnk_section_create', 'section', 'section_create');
    register_callback('cnk_section_delete', 'section', 'section_delete', 1);
	register_callback('cnk_section_save', 'section', 'section_save');
	
	$cnk_tree = NULL;
	$cnk_tree_id = 0;
}
else if (@txpinterface == 'public')
{
	register_callback('cnk_pretext', 'pretext');
	register_callback('cnk_textpattern', 'textpattern');
	
	define('CNK_FRIENDLY_URLS', false);
}

// -------------------------------------------------------------
//
//	TAG: breadcrumb <txp:cnk_breadcrumb />
//
//  PARAMETERS:
//
//	  |*append_article_title* 
//     | appends the article title |('y' ; 'n' ; default: 'n')|
//    |*url_pattern*
//     | defines the href attribute of section links. %s is the section name, %u is site_root_url |(string ; default depends on txp's permlink_mode)|
//
// -------------------------------------------------------------
function cnk_breadcrumb($atts) 
{
	global $cnk_tree, $prefs, $pretext, $s, $id, $title;

	extract(lAtts(array(
		'append_article_title' => 'y',
		'url_pattern' => ''
	), $atts));

	// build url pattern for section links
	if (!$url_pattern)
	{
		switch($prefs['permlink_mode'])
		{
			case 'messy':
				$url_pattern = '%u?s=%s';
				break;
			default:
				$url_pattern = '%u%s';
		}
	}
	
	$path_array = cnk_get_path($s);

	if ($append_article_title == 'y' && $id) 
	{
			$a = safe_row("id, posted, title, url_title", "textpattern", "id=".doSlash($id));
	}
	
	$res = '<div class="cnk_crumbs">';
	
	$path = '/';
	
	for ($i=0; $i < count($path_array); $i++)
	{
		$path .= $path_array[$i]['name'].'/';
	
		$res .= '<span class="cnk_crumb">';
		
		if ($i > 0) $res .= '<span> » </span>';
		
		if ($i == (count($path_array)-1) && !$id)
		{
			$res .= $path_array[$i]['title'];
		}
		else
		{
			$search = array('%s', '%u', '%p');
			$replacement = array($path_array[$i]['name'], hu, $path);
			
			$res .= '<a href="'.str_replace($search, $replacement, $url_pattern).'">'.$path_array[$i]['title'].'</a>';
		}
		
		$res .= '</span>';
	}
	
	if ($append_article_title == 'y' && $id)
	{
		//$search = array('%s', '%a', '%t', '%u', '%y', '%m', '%d');
		//$replacement = array($section_name, $a['id'], $a['url_title'], hu, substr($a['posted'], 0, 4), substr($a['posted'], 5, 2), substr($a['posted'], 8, 2));
		$res .= '<span class="cnk_crumb"><span> » </span>'.$a['title'].'</span>';
	}
	
	$res .= '</div>';
	
	return $res;
}

// -------------------------------------------------------------
//
//	TAG: section tree <txp:cnk_sec_list />
//
//  PARAMETERS:
//
//	  |*active_section_articles* 
//     | whether to show article list of active section or not |('y' ; 'n' ; default: 'n')|
//    |*active_section_article_order_by* 
//     | order article list by |('posted' ; 'lastmod' ; 'title' ; default: 'posted')|
//    |*active_section_article_order* 
//     | order article list direction |('asc' ; 'desc' ; default: 'asc')|
//    |*active_section_article_max_count*
//     | limit articles. if set to 0, all articles will be rendered|(integer ; default: 5)|
//    |*exclude_sections*
//     | comma separated list of section names that should not show up in the tree.
//       subsections of excluded sections won't show up either |('name1','name2' ; default: '')|
//    |*start_section*
//     | name of section that should be rendered exclusively. subsections will also be rendered |(string ; default: '')|
//    |*css_id*
//     | id property of the top-level ul tag |(string ; default: 'cnk_sec_tree')|
//    |*css_active_section_class*
//     | class property of active section element |(string ; default: cnk_active_section)|
//    |*css_active_article_class*
//     | class property of active article element |(string ; default: cnk_active_article)|
//    |*css_article_class*
//     | class property of all article elements|(string ; default: cnk_article)|
//    |*css_section_class*
//     | class property of all section elements|(string ; default: cnk_section)|
//    |*css_section_class_open*
//     | class property of all opened section elements|(string ; default: cnk_section_open)|
//    |*css_section_class_closed*
//     | class property of all closed section elements|(string ; default: cnk_section_closed)|
//    |*show_article_count*
//     | show number of articles with status 4 for every section if at least one article exists. |('y' ; 'n' ; default 'n')|
//    |*show_article_count_bottom*
//     | show number of articles with status 4 only for bottom sections if at least one article exists |('y' ; 'n' ; default 'n')|
//    |*exclude_empties*
//     | don't show sections, which have no articles with status 4 assigned to it |('y' ; 'n' ; default 'n')|
//    |*url_pattern_section*
//     | defines the href attribute of section links. %s is the section name, %u is site_root_url |(string ; default depends on txp's permlink_mode)|
//    |*url_pattern_article*
//     | defines the href attribute of article links. 
//       %s is a placeholder for the section name, %a is article id, %t is article url_title, %u is site_root_url, %y(ear), %m(month), %d(ay) |(string ; default depends on txp's permlink_mode)|
//	  |*section_append_hmtl*
//	   | appends string right before the closing li tag of a section. same placeholders as url_pattern_section |(string ; default '')|
//    |*article_count_pattern*
//     | restyle the article count part of section links. %c is placeholder for article count |(string ; default '(%c)')|
//	  |*expand_all*
//	   | Expands all section elements. If set to 'n' only the active seciton and its parents will be expanded | ('y' ; 'n' ; default 'y')
//
// -------------------------------------------------------------
function cnk_sec_list($atts) 
{
	global $cnk_tree, $cnk_tree_id, $prefs, $s, $id;

	extract(lAtts(array(
		'active_section_articles' => 'y',
		'active_section_article_order_by' => 'posted',
		'active_section_article_order' => 'asc',
		'active_section_article_max_count' => 5,
		'exclude_sections' => '',
		'start_section' => '',
		'css_id' => 'cnk_sec_tree',
		'css_active_section_class' => 'cnk_active_section',
		'css_active_article_class' => 'cnk_active_article',
		'css_article_class' => 'cnk_article',
		'css_section_class' => 'cnk_section',
		'css_section_class_open' => 'cnk_section_open',
		'css_section_class_closed' => 'cnk_section_closed',
		'show_article_count' => 'n',
		'show_article_count_bottom' => 'n',
		'exclude_empties' => 'n',
		'url_pattern_section' => '',
		'url_pattern_article' => '',
		'section_append_html' => '',
		'article_count_pattern' => '(%c)',
		'expand_all' => 'y'
	), $atts));
	
	// build url pattern for section links
	if (!$url_pattern_section)
	{
		switch($prefs['permlink_mode'])
		{
			case 'messy':
				$url_pattern_section = '%u?s=%s';
				break;
			default:
				$url_pattern_section = '%u%s';
		}
	}
	
	// build url pattern for article links
	if (!$url_pattern_article)
	{
		switch($prefs['permlink_mode'])
		{
			case 'messy':
				$url_pattern_article = '%u?id=%a';
				break;
			case 'section_title':
				$url_pattern_article = '%u%s/%t';
				break;
			case 'section_id_title':
				$url_pattern_article = '%u%s/%a/%t';
				break;
			case 'id_title':
				$url_pattern_article = '%u%a/%t';
				break;
			case 'title_only':
				$url_pattern_article = '%u%t';
				break;
			case 'year_month_day_title':
				$url_pattern_article = '%u%y/%m/%d/%t';
				break;
			default:
				$url_pattern_article = '%u?id=%a';
		}
	}
	
	// get lft and rgt value from section node, to decide if sections are in the path
	
	if ($expand_all == 'n')
	{
		$section_node = safe_row('*', 'txp_section', "lower(name) = lower('".doSlash($s)."')");
	}
	
	$is_article_list = safe_count('textpattern', "status = 4 and lower(section) = lower('".doSlash($s)."')")?true:false;
	
	$section_excludes = explode(',', strtolower(str_replace(' ', '', $exclude_sections)));
	
	// create article order clause
	switch(strtolower($active_section_article_order_by)) 
	{
		case 'posted':
			$active_section_article_order_by = 'Posted';
			break;
		case 'lastmod':
			$active_section_article_order_by = 'LastMod';
			break;
		case 'title':
			$active_section_article_order_by = 'Title';
			break;
		default:
			$active_section_article_order_by = 'Posted';
	}

	$active_section_article_order_clause = (strtolower($active_section_article_order) == 'asc')?'asc':'desc';

	$active_section_article_max_count = intval($active_section_article_max_count)?intval($active_section_article_max_count):5;

	$active_section_article_order_clause = " order by ".$active_section_article_order_by." ".$active_section_article_order;
	if ($active_section_article_order_clause > 0) $active_section_article_order_clause .= " limit 0, ".$active_section_article_max_count;

	if ($show_article_count == 'y' || $show_article_count_bottom == 'y' || $exclude_empties == 'y') 
	{
		$count_articles = 'y';
	}
	else
	{
		$count_articles = 'n';
	}
	  
	cnk_st_get_tree($start_section, $count_articles);
	
	//echo "<CNO_DEBUG><pre>".print_r($cnk_tree)."</pre></CNO_DEBUG>";

	$open_list = array();

	$res = '<div id="cnk_section_tree'.($cnk_tree_id?'_'.$cnk_tree_id:'').'"><ul id="'.$css_id.'">';
	
	$strip_level = -1;

	for ($i=0; $i < count($cnk_tree); $i++) 
	{
		if ($cnk_tree[$i]['name'] != 'default') 
		{
			extract($cnk_tree[$i]);

			// close open lists

			while (($c = count($open_list)) && $open_list[$c-1]['level'] >= $level) 
			{
				$res .= '</ul>';

				if ($active_section_articles == 'y' && strtolower($open_list[$c-1]['section']) == strtolower($s) && $is_article_list === true) 
				{
					$res .= cnk_active_article_list($open_list[$c-1]['section'], $active_section_article_order_clause, $url_pattern_article, $css_active_article_class, $css_article_class);
				}
				
				array_pop($open_list);

				$res .= '</li>';
			}
			
			if ($strip_level > -1 && $level <= $strip_level)
			{
				// when we leave the excluded children array, we can render sections again
				$strip_level = -1;
			}
			
			if ($strip_level > -1 || ($exclude_empties == 'y' && $children == 0 && $article_count == 0) || (is_array($section_excludes) && in_array(strtolower($name), $section_excludes)))
			{
				// if section is excluded, subsections should not show up either			
				if ($strip_level < 0) $strip_level = $level;
			}
			else
			{
				$search = array('%s', '%u');
				$replacement = array($name, hu);
					
				if ($id || (strtolower($s) != strtolower($name))) 
				{				
					$res .= '<li class="'.$css_section_class;
					
					if ($expand_all == 'y' || ($expand_all == 'n' && ($section_node['lft'] >= $lft && $section_node['lft'] < $rgt)))
					{
						// section is in path or expand_all is on, so it has to be open
						
						$res .= ' '.$css_section_class_open;
					}
					else
					{
						$res .= ' '.$css_section_class_closed;
					}
					
					$res .= '"><a href="'.str_replace($search, $replacement, $url_pattern_section).'">'.gTxt($title);
					
					if ($article_count > 0 && ($show_article_count == 'y' || ($show_article_count_bottom == 'y' && $children == 0))) $res .= ' '.str_replace('%c', $article_count, $article_count_pattern);
					
					$res .= '</a>'.str_replace($search, $replacement, $section_append_html);
				} 
				else 
				{
					$res .= '<li class="'.$css_section_class.' '.$css_active_section_class;
					
					if ($expand_all == 'y' || ($expand_all == 'n' && ($section_node['lft'] >= $lft && $section_node['lft'] < $rgt)))
					{
						// section is in path or expand_all is on, so it has to be open
						
						$res .= ' '.$css_section_class_open;
					}
					else
					{
						$res .= ' '.$css_section_class_closed;
					}
					
					$res .= '">'.gTxt($title);
					if ($article_count > 0 && ($show_article_count == 'y' || ($show_article_count_bottom == 'y' && $children == 0))) $res .= ' '.str_replace('%c', $article_count, $article_count_pattern);
					$res .= str_replace($search, $replacement, $section_append_html);
				}

				if ($children > 0) 
				{
					$res .= '<ul>';

					array_push($open_list, array('level' => $level, 'section' => $name));

				} 
				else 
				{
					if ($active_section_articles == 'y' && strtolower($s) == strtolower($name) && $is_article_list === true) 
					{
						$res .= cnk_active_article_list($name, $active_section_article_order_clause, $url_pattern_article, $css_active_article_class, $css_article_class);
					}

					$res .= '</li>';
				}
			}
		}
	}

	// close remaining open lists

	while (($c = count($open_list)) && $open_list[$c-1]['level'] >= $level) 
	{
		$res .= '</ul>';

		if ($active_section_articles == 'y' && strtolower($open_list[$c-1]['section']) == strtolower($s) && $is_article_list === true) 
		{
			$res .= cnk_active_article_list($open_list[$c-1]['section'], $active_section_article_order_clause, $url_pattern_article, $css_active_article_class, $css_article_class);
		}
		
		array_pop($open_list);

		$res .= '</li>';
	}
	
	$cnk_tree_id++;

	return $res.'</ul></div>';
}

// -------------------------------------------------------------
//
//	Function: generates section path for specified section
//
// -------------------------------------------------------------
function cnk_get_path($section_name)
{
	// TODO: implement caching

	$path = array();

	$rs = safe_query("SELECT s1.name, s1.title FROM ".safe_pfx('txp_section')." s1, ".safe_pfx('txp_section')." s2 WHERE lower(s2.name) = lower('".doSlash($section_name)."') AND s2.lft BETWEEN s1.lft AND s1.rgt AND s1.name <> 'default' ORDER BY s1.lft");	

	while ($a = nextRow($rs)) 
	{
		array_push($path, $a);
	}
	
	return $path;
}

// -------------------------------------------------------------
//
//	Function: generates article list for active section
//
// -------------------------------------------------------------
function cnk_active_article_list($section_name, $order, $url_pattern_article, $css_active_article_class, $css_article_class) 
{
	global $prefs, $id;

	//echo "<CNO_DEBUG>".$id."</CNO_DEBUG>";

	$res = '<ul>';
	
	$rs = safe_rows_start("id, posted, title, url_title","textpattern", "lower(section)='".strtolower($section_name)."' and status = 4 ".$order);

	while ($a = nextRow($rs)) 
	{

	    if (strtolower($id) != strtolower($a['id'])) 
	    {
			$search = array('%s', '%a', '%t', '%u', '%y', '%m', '%d');
			$replacement = array($section_name, $a['id'], $a['url_title'], hu, substr($a['posted'], 0, 4), substr($a['posted'], 5, 2), substr($a['posted'], 8, 2));

			$res .= '<li class="'.$css_article_class.'"><a href="'.str_replace($search, $replacement, $url_pattern_article).'">'.gTxt($a['title']).'</a></li>';
		} 
		else 
		{
			$res .= '<li class="'.$css_article_class.' '.$css_active_article_class.'">'.gTxt($a['title']);  
		}

	}

	return $res.'</ul>';
}

// -------------------------------------------------------------
//
//	Function: send hooks to correct functions
//
// -------------------------------------------------------------
function cnk_section_tree($event, $step) 
{
	if(!$step or !in_array($step, array( 'cnk_st_install', 'cnk_st_deinstall', 'cnk_section_delete'))) {

		cnk_st_configure();

	} 
	else 
	{
		$step();
	}
}

// -------------------------------------------------------------
//
//	Function: saves sections
//
// -------------------------------------------------------------
function cnk_section_save() 
{
	global $cnk_tree;

	// get section
	$old_name = ps('old_name');
	$name = sanitizeForUrl(ps('name'));
	$parent = ps('parent');
	$old_parent = ps('old_parent');
	$children = ps('children');

	// if name was changed to an existing one, don't update, otherwise fix parent

	if ($old_name && (strtolower($name) != strtolower($old_name))) 
	{
		$chk = fetch('name','txp_section','name',$old_name);

		if ($chk) 
		{
			$cnk_tree = NULL;
			return;
		} 
		else 
		{
			safe_update("txp_section", "parent='".$name."'", "parent='".$old_name."'");
		}
	}

	// if parent changed, update

	if ($parent != $old_parent) 
	{
		// if node has children move complete subtree

		if ($children > 0) 
		{
			cnk_st_move_subtree($name, $parent);
		} 
		else 
		{
			cnk_st_move_node($name, $parent);
		}
	}
	
	$cnk_tree = NULL;
}

// -------------------------------------------------------------
//
//	Function: deletes section (and also all subsections)
//
// -------------------------------------------------------------
function cnk_section_delete() 
{
//	global $cnk_tree;
	
	$name = ps('name');
	
	// only delete, if no articles are found in that section (TXP native behaviour)
	
	if (!safe_count('textpattern', "lower(section) = lower('".$name."')"))
	{

		$n = safe_row("*", "txp_section", "name='".$name."'");

		// delete all subnodes or add them to the parent of the deleted node?

		if (($n['rgt'] - $n['lft']) > 1) 
		{
			// ask where to append subtree or if it should be completely deleted

			//pagetop(gTxt('sections'));

			/* This codes deletes the complete subtree

			$width = $n['rgt'] - $n['lft'] + 1;

			safe_delete('txp_section', "lft between ".$n['lft']." AND ".$n['rgt']);

			safe_update("txp_section","rgt=rgt-".$width, "rgt > ".$n['rgt']);

			safe_update("txp_section","lft=lft-".$width, "lft > ".$n['rgt']);
			*/
			
			// delete section and move all children one level up
			
		//	safe_delete('txp_section', "lft=".$n['lft']);
			
			safe_update('txp_section', "lft=lft-1, rgt=rgt-1, level=level-1", "lft between ".$n['lft']." AND ".$n['rgt']);
			
			safe_update("txp_section", "parent='".$n['parent']."'", "parent='".$n['name']."'");
			
			safe_update("txp_section", "rgt=rgt-2", "rgt > ".$n['rgt']);

			safe_update("txp_section", "lft=lft-2", "lft > ".$n['rgt']);
		} 
		else 
		{
		//	safe_delete('txp_section', "name = '".$name."'");

			safe_update("txp_section","rgt=rgt-2", "rgt > ".$n['rgt']);

			safe_update("txp_section","lft=lft-2", "lft > ".$n['rgt']);
		}
	}
		
	//	$cnk_tree = NULL;

	//	$message = gTxt('section_deleted', array('{name}' => $name));

	//	header("Location: ?event=section&message=".urlencode($message)); exit;
}

// -------------------------------------------------------------
//
//	Function: creates new section
//
// -------------------------------------------------------------
function cnk_section_create() 
{
	// get name
	$name = sanitizeForUrl(ps('name'));

	// if new section was created, add to node to default
  
	$chk = safe_field('name','txp_section',"name='".$name."' and lft is null");

	if ($chk) cnk_st_add_node($name, 'default');
}

// -------------------------------------------------------------
//
//	Function: adds additional form elements to section view
//
// -------------------------------------------------------------
function cnk_section_list() 
{
	ob_start('cnk_section_inject');
}

function cnk_section_inject($buffer)
{
	global $DB, $prefs;

	if(!isset($DB)) $DB = new db;

	if(!isset($prefs)) $prefs = get_prefs();
		
	// add tree javascript and css
	$script = '<script type="text/javascript">
					function cnk_move_up(section)
					{
						// nothing
					}
					
					function cnk_move_down(section)
					{
						// nothing
					}
				</script>
				<style>
					#cnk_section_tree li
					{
						margin: 4px 0px 4px 10px !important;
					}
				</style>';
				
	$buffer = str_replace('<script type="text/javascript" src="jquery.js"></script>', '<script type="text/javascript" src="jquery.js"></script>'.$script, $buffer);
	
	// add section tree navigation above form
	$move_links = ''; //' <a href="javascript:cnk_move_up(\'%s\');">u</a> <a href="javascript:cnk_move_down(\'%s\');">d</a>';
	$navigation = cnk_sec_list(array('active_section_articles' => 'n', 'url_pattern_section' => '#section-%s', 'section_append_html' => $move_links));
	$buffer = str_replace('</h1>', '</h1>'.$navigation, $buffer);

	// replace delete event
	//$buffer = str_replace('<input type="hidden" name="event" value="section" /><input type="hidden" name="step" value="section_delete" />', '<input type="hidden" name="event" value="cnk_section_tree" /><input type="hidden" name="step" value="cnk_section_delete" />', $buffer);

	// insert section tree code

	$pattern = '#(<tr><td colspan="2" class="noline"><input type="submit" name="" value=".*" class="smallerbox" /><input type="hidden" name="event" value="section" /><input type="hidden" name="step" value="section_save" /><input type="hidden" name="old_name" value="(.*)" />)#m';

	$insert = 'cnk_st_dropdown';
	$buffer = preg_replace_callback($pattern, $insert, $buffer);

	return $buffer;
}

// -------------------------------------------------------------
//
//	Function: replaces section dropdown with section tree
//			  in write tab
//
// -------------------------------------------------------------
function cnk_article_view() 
{
	ob_start('cnk_write_inject');
}

function cnk_write_inject($buffer)
{
	global $DB;

	if(!isset($DB)) $DB = new db;
	
	if(!isset($prefs)) $prefs = get_prefs();
		
	// replace section dropdown TODO: tidy up regexp!

	$pattern = '#name="Section".*</select></p>#sU';
	if (gps('step') == 'edit') $pattern = '#(name="Section".*<option value="(.*)" selected="selected">.*</select></p>)#s';

	$insert = 'cnk_st_dropdown_article';
	$buffer = preg_replace_callback($pattern, $insert, $buffer);

	return $buffer;
}

// -------------------------------------------------------------
//
//	Function: cnk_article_view() helper function
//
// -------------------------------------------------------------
function cnk_st_dropdown_article($matches) 
{
	global $cnk_tree;

	$found_name = isset($matches[2])?$matches[2]:'';

	cnk_st_get_tree();

	$res = 'name="Section" class="list">';

	for ($i=1; $i < count($cnk_tree); $i++) //hides default
	{
		extract($cnk_tree[$i]);

		$res .= '<option value="'.$name.'" '.((strtolower($name)==strtolower($found_name))?'selected="selected"':'').'>'.cnk_st_spaces($level-1).gTxt($title).'</option>';
	}

	$res .= '</select>';

	return $res;
}

// -------------------------------------------------------------
//
//	Function: cnk_section_list() helper function
//
// -------------------------------------------------------------
function cnk_st_dropdown($matches) 
{
	global $cnk_tree;
  
	cnk_st_get_tree();

	$node = cnk_st_get_node($matches[2]);
	$sec_parent = $node['parent'];
	$stop_rgt = 0;

	$res = '<tr><td class="noline" style="text-align: right; vertical-align: middle;">Parent: </td>	<td class="noline"><select name="parent" class="list">';

	for ($i=0; $i < count($cnk_tree); $i++)
	{
		extract($cnk_tree[$i]);

		// don't show the node itself or it's children

		if ($stop_rgt < $rgt) 
		{
			if ($name != $matches[2]) 
			{
				$res .= '<option value="'.$name.'" '.(($name==$sec_parent)?'selected="selected"':'').'>'.cnk_st_spaces($level).gTxt($title).'</option>';
			} 
			else 
			{
				$stop_rgt = $rgt;
			}
		}
	}

	$res .= '</select><input type="hidden" name="children" value="'.$node['children'].'" /><input type="hidden" name="old_parent" value="'.$sec_parent.'" /></td></tr>';
	$res .= '<tr><td class="noline">&nbsp;</td><td class="noline"><a href="#cnk_section_tree">^ go up to tree</a></td></tr>'.$matches[1];
	
	return $res;
}

// -------------------------------------------------------------
//
//	Function: internal nested set function to get a node
//
// -------------------------------------------------------------
function cnk_st_get_node($child_name) 
{
	global $cnk_tree;
  
	cnk_st_get_tree();

	$i = 0;

	for($i=0; $i < count($cnk_tree); $i++) 
	{
		if ($cnk_tree[$i]['name'] == $child_name) return $cnk_tree[$i];
	}
}

// -------------------------------------------------------------
//
//	Function: internal helper function to indent strings
//
// -------------------------------------------------------------
function cnk_st_spaces($count) 
{
	$str = '';

	for ($i=0; $i < $count; $i++) $str .= '-';

	return $str.' ';
}

// -------------------------------------------------------------
//
//	Function: internal nested set function to get the tree
//			  or a subtree specified by parent_node
//
// -------------------------------------------------------------
function cnk_st_get_tree($parent_node = '', $count_articles = 'n') 
{
	global $cnk_tree;

	if ($cnk_tree === NULL || $parent_node != $cnk_tree[0]['name'] || (!isset($cnk_tree[0]['article_count']) && $count_articles == 'y')) 
	{
		$cnk_tree = array();
		
		$sql_fields = "s.name, s.title, s.parent, s.level, s.lft, s.rgt";
		
		if ($parent_node != '')
		{
			$sql_tables = safe_pfx('txp_section')." s LEFT JOIN ".safe_pfx('txp_section')." p ON s.lft >= p.lft AND s.rgt <= p.rgt";
			$sql_where = "lower(p.name) = '".doSlash(strtolower($parent_node))."' GROUP BY s.lft asc";
		}
		else
		{
			$sql_tables = safe_pfx('txp_section')." s";
			$sql_where = "1=1 GROUP BY s.lft asc";
		}
		
		if ($count_articles == 'y')
		{	
			$sql_fields .= ", count(a.section) AS article_count";
			$sql_tables .= " LEFT JOIN ".safe_pfx('textpattern')." a ON a.section = s.name AND a.status = 4";
			$sql_where = $sql_where.", s.name";
		}

		$rs = safe_query("SELECT ".$sql_fields." FROM ".$sql_tables." WHERE ".$sql_where);

		while ($a = nextRow($rs)) 
		{
			$a['children'] = ($a['rgt'] - $a['lft'] - 1) / 2;
			
			if (!isset($a['article_count'])) $a['article_count'] = 0;
			
			array_push($cnk_tree, $a);
		}
	}
}

// -------------------------------------------------------------
//
//	Function: Prints out configuration menu
//
//  TODO: Make pretty!
//
// -------------------------------------------------------------
function cnk_st_configure($message='') 
{
	pagetop('Section Tree Configuration', $message);

	echo "<a href=\"?event=cnk_section_tree&#38;step=cnk_st_install\">Install</a>";
	echo "<br /><br />";
	echo "<a href=\"?event=cnk_section_tree&#38;step=cnk_st_deinstall\">Deinstall</a>";
}

// -------------------------------------------------------------
//
//	Function: install
//
// -------------------------------------------------------------
function cnk_st_install($message='') 
{
	pagetop('Section Tree Configuration', $message);

	$res = true;

	// add columns

	if (!safe_query("ALTER TABLE ".safe_pfx("txp_section")." ADD parent VARCHAR(128);")) $res = false;
	if (!safe_query("ALTER TABLE ".safe_pfx("txp_section")." ADD level TINYINT;")) $res = false;
	if (!safe_query("ALTER TABLE ".safe_pfx("txp_section")." ADD lft INT(12);")) $res = false;
	if (!safe_query("ALTER TABLE ".safe_pfx("txp_section")." ADD rgt INT(12);")) $res = false;

	// update root

	if (!safe_update("txp_section","level=0, lft=1, rgt=2", "name='default'")) $res = false;

	// get other sections and add to root

	$rs = safe_rows_start("name","txp_section", "lft is null");

	while ($a = nextRow($rs)) 
	{
		extract($a);

		if (!cnk_st_add_node($name, 'default')) $res = false;
	}
	
	// add indexes TODO: fix nested set functions to be unique index safe, until then we use just index
	if (!safe_query('ALTER TABLE `'.safe_pfx('txp_section').'` ADD INDEX (`rgt`)')) $res = false;
	if (!safe_query('ALTER TABLE `'.safe_pfx('txp_section').'` ADD INDEX (`lft`)')) $res = false;

	if ($res) 
	{
		echo "Installation was successful!";
	} 
	else 
	{
		echo "Installation was not successful!";
	}
}

// -------------------------------------------------------------
//
//	Function: deinstall
//
// -------------------------------------------------------------
function cnk_st_deinstall($message='') 
{
	pagetop('Section Tree Configuration', $message);

	$res = true;

	// remove columns

	if (!safe_query("ALTER TABLE ".safe_pfx("txp_section")." DROP COLUMN parent;")) $res = false;
	if (!safe_query("ALTER TABLE ".safe_pfx("txp_section")." DROP COLUMN level;")) $res = false;
	if (!safe_query("ALTER TABLE ".safe_pfx("txp_section")." DROP COLUMN lft;")) $res = false;
	if (!safe_query("ALTER TABLE ".safe_pfx("txp_section")." DROP COLUMN rgt;")) $res = false;
	
	// drop indexes
	//if (!safe_query('ALTER TABLE `'.safe_pfx(txp_section).'` DROP UNIQUE (`rgt`)')) $res = false;
	//if (!safe_query('ALTER TABLE `'.safe_pfx(txp_section).'` DROP UNIQUE (`lft`)')) $res = false;

	if ($res) 
	{
		echo "Deinstallation was successful!";
	} 
	else 
	{
		echo "Deinstallation was not successful!";
	}
}

// -------------------------------------------------------------
//
//	Function: internal nested set function to add a node
//			  currently redirects to cnk_st_add_node_end()
//
// -------------------------------------------------------------
function cnk_st_add_node($node_name, $parent_name) 
{
	return cnk_st_add_node_end($node_name, $parent_name);
}

// -------------------------------------------------------------
//
//	Function: internal nested set function to add a node at
//			  last position within the parent node
//
// -------------------------------------------------------------
function cnk_st_add_node_end($node_name, $parent_name) 
{
	// get parent rgt

	$p = safe_row("level, rgt", "txp_section", "name='".$parent_name."'");

	// update parents

	if (!safe_update("txp_section","rgt=rgt+2", "rgt >= ".$p['rgt'])) return false;

	if (!safe_update("txp_section","lft=lft+2", "lft > ".$p['rgt'])) return false;

	// update node

	if (!safe_update("txp_section","parent='".$parent_name."', level=".$p['level']."+1, lft=".$p['rgt'].", rgt=".$p['rgt']."+1", "name = '".$node_name."'")) return false;

	return true;
}

// -------------------------------------------------------------
//
//	Function: internal nested set function to move a node
//
// -------------------------------------------------------------
function cnk_st_move_node($node_name, $parent_name) 
{

	$n = safe_row("rgt", "txp_section", "name='".$node_name."'");

	if (cnk_st_add_node_end($node_name, $parent_name) === true) 
	{
		if (!safe_update("txp_section","rgt=rgt-2", "rgt > ".$n['rgt'])) return false;

		if (!safe_update("txp_section","lft=lft-2", "lft > ".$n['rgt'])) return false;

		return true;
	} 
	else 
	{
		return false;
	}
}

// -------------------------------------------------------------
//
//	Function: internal nested set function to move a subtree
//
// -------------------------------------------------------------
function cnk_st_move_subtree($node_name, $parent_name) 
{
	$n = safe_row("rgt, lft, level", "txp_section", "name='".$node_name."'");

	$p = safe_row("rgt, lft, level", "txp_section", "name='".$parent_name."'");

	$width = $n['rgt'] - $n['lft'] + 1;

	$level_diff = $p['level'] - $n['level'] + 1;

	$diff = $p['rgt'] - $n['lft'];

	// let's first update parent information of the anchor node

	if (!safe_update("txp_section","parent='".$parent_name."'", "name = '".$node_name."'")) return false;

	// create place for subtree

	if (!safe_update("txp_section","rgt=rgt+".$width, "rgt >= ".$p['rgt'])) return false;

	if (!safe_update("txp_section","lft=lft+".$width, "lft > ".$p['rgt'])) return false;

	if ($p['lft'] < $n['lft']) 
	{
		$n['lft'] = $n['lft'] + $width;

		$n['rgt'] = $n['rgt'] + $width;

		$diff = $diff - $width;
	}

	// move subtree

	if (!safe_update("txp_section","lft = lft + (".$diff."), rgt = rgt + (".$diff."), level=level+(".$level_diff.")", "lft between ".$n['lft']." AND ".$n['rgt'])) return false;

	// close hole

	if (!safe_update("txp_section","rgt=rgt-".$width, "rgt > ".$n['rgt'])) return false;

	if (!safe_update("txp_section","lft=lft-".$width, "lft > ".$n['rgt'])) return false;
}

// -------------------------------------------------------------
//
//	Function: Hooks into txp's pretext function to takeover url
//			  analyzing.
//
// -------------------------------------------------------------
function cnk_pretext()
{
	global $prefs;

	// only takeover url algorithm when in section_title mode
	if (CNK_FRIENDLY_URLS && $prefs['permlink_mode'] == 'section_title')
	{
		extract($prefs);
		$out = array();
		
		// some useful vars for taghandlers, plugins
		$out['request_uri'] = preg_replace("|^https?://[^/]+|i","",serverSet('REQUEST_URI'));
		$out['qs'] = serverSet('QUERY_STRING');
		
		// IIS fix
		if (!$out['request_uri'] and serverSet('SCRIPT_NAME'))
		$out['request_uri'] = serverSet('SCRIPT_NAME').( (serverSet('QUERY_STRING')) ? '?'.serverSet('QUERY_STRING') : '');
		
		// another IIS fix
		if (!$out['request_uri'] and serverSet('argv'))
		{
				$argv = serverSet('argv');
				$out['request_uri'] = @substr($argv[0], strpos($argv[0], ';') + 1);
		}
		
		$subpath = preg_quote(preg_replace("/https?:\/\/.*(\/.*)/Ui","$1",hu),"/");
		$req = preg_replace("/^$subpath/i","/",$out['request_uri']);

		$url_chunks = explode('/', trim($req, '/'));
		$req = '/'.implode('/', array_slice($url_chunks, -2));
		
		//echo $req;
		
		extract(chopUrl($req));

		//first we sniff out some of the preset url schemes
		if (strlen($u1)) 
		{
			switch($u1) 
			{
				case 'atom':
						include txpath.'/publish/atom.php'; exit(atom());

				case 'rss':
						include txpath.'/publish/rss.php'; exit(rss());

				// urldecode(strtolower(urlencode())) looks ugly but is the only way to
				// make it multibyte-safe without breaking backwards-compatibility
				case urldecode(strtolower(urlencode(gTxt('section')))):
						$out['s'] = (ckEx('section',$u2)) ? $u2 : ''; break;

				case urldecode(strtolower(urlencode(gTxt('category')))):
						$out['c'] = (ckEx('category',$u2)) ? $u2 : ''; break;

				case urldecode(strtolower(urlencode(gTxt('author')))):
						$out['author'] = (!empty($u2)) ? $u2 : ''; break;
						// AuthorID gets resolved from Name further down

				case urldecode(strtolower(urlencode(gTxt('file_download')))):
						$out['s'] = 'file_download';
						$out['id'] = (!empty($u2)) ? $u2 : ''; break;

				default:
						// then see if the prefs-defined permlink scheme is usable
						switch ($permlink_mode) {
								/*
								case 'section_id_title':
										if (empty($u2)) 
										{
												$out['s'] = (ckEx('section',$u1)) ? $u1 : '';
										}
										else 
										{
												$rs = lookupByIDSection($u2, $u1);
												$out['s'] = @$rs['Section'];
												$out['id'] = @$rs['ID'];
										}
								break;

								case 'year_month_day_title':
										if (empty($u2)) 
										{
												$out['s'] = (ckEx('section',$u1)) ? $u1 : '';
										}
										elseif (empty($u4))
										{
												$month = "$u1-$u2";
												if (!empty($u3)) $month.= "-$u3";
												if (preg_match('/\d+-\d+(?:-\d+)?/', $month)) {
														$out['month'] = $month;
														$out['s'] = 'default';
												}
										}
										else
										{
												$when = "$u1-$u2-$u3";
												$rs = lookupByDateTitle($when,$u4);
												$out['id'] = (!empty($rs['ID'])) ? $rs['ID'] : '';
												$out['s'] = (!empty($rs['Section'])) ? $rs['Section'] : '';
										}
								break;
								*/
								case 'section_title':
										if (empty($u2)) 
										{
												$out['s'] = (ckEx('section',$u1)) ? $u1 : '';
										}
										else 
										{
												// match section/title
												$rs = lookupByTitleSection($u2,$u1);
												
												if (count($rs))
												{
													// check path TODO: move to function
													/*
													$rs_path = safe_rows("name", "txp_section", "lft <= ".$rs['lft']." and ((rgt-lft) > 1 OR lft = ".$rs['lft'].") and name != 'default' order by lft");
													
													$path = '/';
													
													for($i=0; $i < count($rs_path); $i++)
													{
														$path .= $rs_path[$i]['name'].'/';
													}
													
													if ($path == '/'.implode('/', $url_chunks).'/') */
													{														
														$out['id'] = @$rs['ID'];
														$out['s'] = @$rs['Section'];
													}
												}
												else
												{
													// match parentsection/section
													$rs = safe_row("name, lft",'txp_section',"lower(name) like '".doSlash($u2)."' AND lower(parent)='".doSlash($u1)."' limit 1");
													
													if (count($rs))
													{
														// check path TODO: move to function
														$rs_path = safe_rows("name", "txp_section", "lft <= ".$rs['lft']." and ((rgt-lft) > 1 OR lft = ".$rs['lft'].") and name != 'default' order by lft");
														
														$path = '/';
														
														for($i=0; $i < count($rs_path); $i++)
														{
															$path .= $rs_path[$i]['name'].'/';
														}
														
														if ($path == '/'.implode('/', $url_chunks).'/')
														{														
															$out['s'] = @$rs['name'];
														}
													}
												}
										}
								break;
								/*
								case 'title_only':
										$rs = lookupByTitle($u1);
										$out['id'] = @$rs['ID'];
										$out['s'] = (empty($rs['Section']) ? ckEx('section', $u1) : $rs['Section']);
								break;

								case 'id_title':
										if (is_numeric($u1) && ckExID($u1))
										{
												$rs = lookupByID($u1);
												$out['id'] = (!empty($rs['ID'])) ? $rs['ID'] : '';
												$out['s'] = (!empty($rs['Section'])) ? $rs['Section'] : '';
										}
										else
										{
												# We don't want to miss the /section/ pages
												$out['s']= ckEx('section',$u1)? $u1 : '';
										}
								break; */
						}
				}
		} 
		else 
		{
			$out['s'] = 'default';
		}
		
		//print_r($out);
		if (isset($out['id'])) $_GET['id'] = $out['id'];
		if (isset($out['s'])) $_GET['s'] = $out['s'];
	}
}

// -------------------------------------------------------------
//
//	Function: Hooks into txp's textpattern function to takeover
//			  url rewriting.
//
// -------------------------------------------------------------
function cnk_textpattern()
{
	global $prefs, $pretext, $permlink_mode;

	// only takeover url algorithm when in section_title mode
	if (CNK_FRIENDLY_URLS && $prefs['permlink_mode'] == 'section_title')
	{
		// tell textpattern to use messy urls
		$permlink_mode = 'messy';
		
		@ob_start('cnk_override_buffer');
	}
}

// -------------------------------------------------------------
//
//	Function: Hooks into txp's textpattern end function to takeover
//			  url rewriting.
//
// -------------------------------------------------------------
function cnk_textpattern_end() 
{
	global $prefs;
	
	// only takeover url algorithm when in section_title mode
	if (CNK_FRIENDLY_URLS && $prefs['permlink_mode'] == 'section_title')
	{
		@ob_end_flush(); exit;
	}
}

// -------------------------------------------------------------
//
//	Function: internal page buffer url rewriting function
//
// -------------------------------------------------------------
function cnk_override_buffer($buffer) 
{
	global $pretext, $production_status;

	$buffer = preg_replace_callback('%href="('.hu.'|\?)([^"]*)"%', 'cnk_replace_pageurls', $buffer);

	return $buffer;
}

// -------------------------------------------------------------
//
//	Function: internal page buffer url rewriting callback function
//
// -------------------------------------------------------------
function cnk_replace_pageurls($parts) 
{
	extract(lAtts(array(
		'path'		=> 'index.php',
		'query'		=> '',
		'fragment'	=> '',
	), parse_url(html_entity_decode(str_replace('&#38;', '&', $parts[2])))));

	// Tidy up links back to the site homepage
	if ($path == 'index.php' && empty($query)) 
	{
		return 'href="' .hu. '"';
	}
	// Fix matches like href="?s=foo"
	else if ($path && empty($query) && $parts[1] == '?') 
	{
		$query = $path;
		$path = 'index.php';
	}

	// Check to see if there is query to work with.
	else if (empty($query) || $path != 'index.php' || strpos($query, '/') === true)
	{
		return $parts[0];
	}

	// '&amp;' will break parse_str() if they are found in a query string
	$query = str_replace('&amp;', '&', $query);

	if ($fragment) $fragment = '#'.$fragment;

	global $pretext;
	parse_str($query, $query_part);
	
	if (!array_key_exists('pg', $query_part)) $query_part['pg'] = 0;
	if (!array_key_exists('id', $query_part)) $query_part['id'] = 0;
	if (!array_key_exists('rss', $query_part)) $query_part['rss'] = 0;
	if (!array_key_exists('atom', $query_part)) $query_part['atom'] = 0;
/*	if ($this->pref('join_pretext_to_pagelinks'))
	{
		extract(array_merge($pretext, $query_part));
/*	}
	else
*/	{
		extract($query_part);
	}
	
	// We have a id, pass to permlinkurl()
	if ($id) 
	{
		if (@$s == 'file_download') 
		{
			//$url = $this->toggle_permlink_mode('filedownloadurl', $id); TODO!!
		} 
		else 
		{
			$rs = safe_row('section, url_title', 'textpattern', "id = ".doSlash($id));
			if (!count($rs)) 
			{ 
				$url = 'chriloi:'.$id; 
			} 
			else 
			{ 
				$url = hu.'/'.@$rs['section'].'/'.@$rs['url_title'].$fragment; // make section_title link
			}
		}
		
		return 'href="'.$url.'"';
	}

	if (@$s == 'default') unset($s);

	// Some TxP tags, e.g. <txp:feed_link /> use 'section' or 'category' inconsistent
	// with most other tags. Process these now so we only have to check $s and $c.
	if (@$section && !$s) $s = $section;
	if (@$category && !$c) $c = $category;
/*
	if (@$pretext['permlink_override']) {
		$override_ids = explode(',', $pretext['permlink_override']);
		foreach ($override_ids as $override_id) {
			$pl = $this->get_permlink($override_id);
			if (count($pl) > 0) $permlinks[] = $pl;
		}
	}

	if (empty($permlinks)) {
		$permlinks = $this->get_all_permlinks(1);

		$permlinks['gbp_permanent_links_default'] = array(
			'components' => array(
				array('type' => 'text', 'text' => strtolower(urlencode(gTxt('category')))),
				array('type' => 'category'),
			),
			'settings' => array(
				'pl_name' => 'gbp_permanent_links_default', 'pl_precedence' => '', 'pl_preview' => '',
				'con_section' => '', 'con_category' => '', 'des_section' => '', 'des_category' => '',
				'des_permlink' => '', 'des_feed' => '', 'des_location' => '',
		));
	}

	$highest_match_count = null;
	foreach ($permlinks as $key => $pl) {
		$this->buffer_debug[] = 'Testing permlink: '. $pl['settings']['pl_name'] .' - '. $key;
		$this->buffer_debug[] = 'Preview: '. $pl['settings']['pl_preview'];
		$out = array(); $match_count = 0;
		foreach ($pl['components'] as $pl_c) {
			switch ($pl_c['type']) {
				case 'text':
					$out[] = $pl_c['text'];
					$match_count--;
				break;
				case 'regex':
					$out[] = $pretext['permlink_regex_'.$pl_c['name']];
					$match_count--;
				break;
				case 'section':
					if (@$s) $out[] = $s;
					else break 2;
				break;
				case 'category':
					if (@$c) $out[] = $c;
					else break 2;
				break;
				case 'feed':
					if (@$rss) $out[] = 'rss';
					else if (@$atom) $out[] = 'atom';
					else break 2;
				break;
				case 'search':
					if (@$q) $out[] = $q;
					else break 2;
				break;
				default: break 2;
			}
				if (!in_array($pl_c['type'], array('title', 'id')))
					$match_count++;
				else break;
		}

		$this->buffer_debug[] = 'Match count: '. $match_count;

		// Todo: Store according to the precedence value
		if (count($out) > 0 && ($match_count > $highest_match_count || !isset($highest_match_count)) &&
		!($key == 'gbp_permanent_links_default' && !$match_count)) {
			extract($pl['settings']);
			if ((empty($s) && empty($c)) ||
			(empty($con_section) || @$s == $con_section) ||
			(empty($con_category) || @$c == $con_category)) {
				$this->buffer_debug[] = 'New highest match! '. implode('/', $out);
				$highest_match_count = $match_count;
				$match = $out;
			}
		}
	}

	if (empty($match) && (!(@$pg && $this->pref('clean_page_archive_links')) || (@$pg && @$q))) {
		global $prefs, $pretext, $permlink_mode;
		$this->buffer_debug[] = 'No match';
		$this->buffer_debug[] = '----';
		$pretext['permlink_mode'] = $permlink_mode = $prefs['permlink_mode'];
		$url = pagelinkurl($query_part);
		$pretext['permlink_mode'] = $permlink_mode = 'messy';
		return 'href="'. $url .'"';
	}

	$this->buffer_debug[] = serialize($match);

	$url = '/'.join('/', $match);
	$url = rtrim(hu, '/').rtrim($url, '/').'/';

	if ($rss)
		$url .= 'rss';
	else if ($atom)
		$url .= 'atom';
	else if ($this->pref('clean_page_archive_links') && $pg)
		$url .= $pg;
	else if ($pg) {
		$url .= '?pg='. $pg;
		$omit_trailing_slash = true;
	}

	$url = rtrim($url, '/') . '/';

	if (@$omit_trailing_slash || $this->pref('omit_trailing_slash'))
		$url = rtrim($url, '/');

	$this->buffer_debug[] = $url;
	$this->buffer_debug[] = '----';

	if ($path == 'index.php' && $url != hu)
		return 'href="'. $url . $fragment .'"';

	/*
	1 = index, textpattern/css, NULL (=index)
	2 = id, s, section, c, category, rss, atom, pg, q, (n, p, month, author)
	*/
	
	return $parts[0];
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---

# --- END PLUGIN HELP ---
-->
<?php
}
?>
