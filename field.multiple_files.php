<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * PyroStreams Relationship Field Type, 
 *
 * @package		PyroCMS\Core\Modules\Streams Core\Field Types
 * @author		Rigo B Castro
 * @copyright           Copyright (c) 2011 - 2013, Rigo B Castro
 */
class Field_multiple_files {

    public $field_type_slug = 'multiple_files';
    public $db_col_type = false;
    public $custom_parameters = array('folder', 'upload_url', 'create_table', 'new_table_name', 'table_name', 'resource_id_column', 'file_id_column', 'max_limit_files', 'allowed_types');
    public $version = '1.1.0';
    public $author = array('name' => 'Rigo B Castro', 'url' => 'http://rigobcastro.com');

    // --------------------------------------------------------------------------

    /**
     * Run time cache
     */
    private $cache;

    // --------------------------------------------------------------------------

    public function event()
    {
        $this->CI->type->add_misc('<link href="//netdna.bootstrapcdn.com/font-awesome/3.2.1/css/font-awesome.css" rel="stylesheet">');
        $this->CI->type->add_misc('<script src="//cdnjs.cloudflare.com/ajax/libs/handlebars.js/1.0.0/handlebars.min.js"></script>');

        $this->CI->type->add_css('multiple_files', 'style.css');
        $this->CI->type->add_js('multiple_files', 'browserplus-min.js');
        $this->CI->type->add_js('multiple_files', 'plupload.full.js');
    }

    /**
     * Output form input
     *
     * @param	array
     * @param	array
     * @return	string
     */
    public function form_output($data, $entry_id, $field)
    {
        if (is_null($entry_id))
        {
            return 'Puede subir múltiples archivos en el modo edición.';
        }

        $this->_clean_files($field);

        $upload_url = site_url('admin/files/upload');

        $data = array(
            'multipart_params' => array(
                $this->CI->security->get_csrf_token_name() => $this->CI->security->get_csrf_hash(),
                'folder_id' => $field->field_data['folder'],
            ),
            'upload_url' => $upload_url,
            'is_new' => empty($entry_id),
            'field_path' => $this->ft_path
        );

        if (!empty($entry_id))
        {
            $table_data = $this->_table_data($field);
            $files_out = array();

            $this->CI->db->join('files as F', "F.id = {$table_data->table}.{$table_data->file_id_column}");

            $files = $this->CI->db->get_where($table_data->table, array(
                    $this->CI->db->dbprefix($table_data->table) . '.' . $table_data->resource_id_column => $entry_id
                ))->result();

            if (!empty($files))
            {
                foreach ($files as $file)
                {
                    $files_out[] = array(
                        'id' => $file->{$table_data->file_id_column},
                        'name' => $file->name,
                        'size' => $file->filesize * 1000,
                        'url' => str_replace('{{ url:site }}', base_url(), $file->path),
                        'is_new' => false
                    );
                }

                $data['files'] = $files_out;
            }
        }


        return $this->CI->type->load_view('multiple_files', 'plupload_js', $data);
    }

    // --------------------------------------------------------------------------

    /**
     * User Field Type Query Build Hook
     *
     * This joins our user fields.
     *
     * @access 	public
     * @param 	array 	&$sql 	The sql array to add to.
     * @param 	obj 	$field 	The field obj
     * @param 	obj 	$stream The stream object
     * @return 	void
     */
    public function query_build_hook(&$sql, $field, $stream)
    {
        $sql['select'][] = $this->CI->db->protect_identifiers($stream->stream_prefix . $stream->stream_slug . '.id', true) . "as `" . $field->field_slug . "||{$field->field_data['resource_id_column']}`";
    }

    // --------------------------------------------------------------------------

    public function pre_save($images, $field, $stream, $row_id, $data_form)
    {
        $table_data = $this->_table_data($field);
        $table = $table_data->table;
        $resource_id_column = $table_data->resource_id_column;
        $file_id_column = $table_data->file_id_column;
        $max_limit_images = (int) $field->field_data['max_limit_images'];

        if (!empty($max_limit_images))
        {
            if (count($images) > $max_limit_images)
            {
                $this->CI->session->set_flashdata('notice', sprintf(lang('streams:multiple_files.max_limit_error'), $max_limit_images));
            }
        }

        if ($this->CI->db->table_exists($table))
        {
            $this->CI->db->trans_begin();

            // Reset
            if ($this->CI->db->delete($table, array($resource_id_column, $row_id)))
            {
                $count = 1;
                // Insert new images
                foreach ($images as $file_id)
                {
                    $check = !empty($max_limit_images) ? $count <= $max_limit_images : true;

                    if ($check)
                    {
                        if (!$this->CI->db->insert($table, array(
                                $resource_id_column => $row_id,
                                $file_id_column => $file_id
                            )))
                        {
                            $this->CI->session->set_flashdata('error', 'Error al guardar los archivos');
                            return false;
                        }
                    }

                    $count++;
                }
            }

            if ($this->CI->db->trans_status() === FALSE)
            {
                $this->CI->db->trans_rollback();
                $this->CI->session->set_flashdata('error', 'Error al guardar los archivos');
                return false;
            }
            else
            {
                $this->CI->db->trans_commit();
            }
        }
    }

    /**
     * Pre Ouput
     *
     * Process before outputting on the CP. Since
     * there is less need for performance on the back end,
     * this is accomplished via just grabbing the title column
     * and the id and displaying a link (ie, no joins here).
     *
     * @access	public
     * @param	array 	$input 	
     * @return	mixed 	null or string
     */
    public function pre_output($input, $data)
    {

        if (!$input)
            return null;


        $stream = $this->CI->streams_m->get_stream($data['choose_stream']);

        $title_column = $stream->title_column;

        // -------------------------------------
        // Data Checks
        // -------------------------------------
        // Make sure the table exists still. If it was deleted we don't want to
        // have everything go to hell.
        if (!$this->CI->db->table_exists($stream->stream_prefix . $stream->stream_slug))
        {
            return null;
        }

        // We need to make sure the select is NOT NULL.
        // So, if we have no title column, let's use the id
        if (trim($title_column) == '')
        {
            $title_column = 'id';
        }

        // -------------------------------------
        // Get the entry
        // -------------------------------------

        $row = $this->CI->db
            ->select()
            ->where('id', $input)
            ->get($stream->stream_prefix . $stream->stream_slug)
            ->row_array();

        if ($this->CI->uri->segment(1) == 'admin')
        {
            if (isset($data['link_uri']) and !empty($data['link_uri']))
            {
                return '<a href="' . site_url(str_replace(array('-id-', '-stream-'), array($row['id'], $stream->stream_slug), $data['link_uri'])) . '">' . $row[$title_column] . '</a>';
            }
            else
            {
                return '<a href="' . site_url('admin/streams/entries/view/' . $stream->id . '/' . $row['id']) . '">' . $row[$title_column] . '</a>';
            }
        }
        else
        {
            return $row;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Pre Ouput Plugin
     * 
     * This takes the data from the join array
     * and formats it using the row parser.
     *
     * @access	public
     * @param	array 	$row 		the row data from the join
     * @param	array  	$custom 	custom field data
     * @param	mixed 	null or formatted array
     */
    public function pre_output_plugin($row, $custom)
    {

        $table = $custom['field_data']['table_name'];

        if (empty($table))
        {
            $table = "{$custom['stream_slug']}_{$custom['field_slug']}";
        }

        $file_id_column = !empty($custom['field_data']['file_id']) ? $custom['field_data']['file_id'] : 'file_id';


        $files = $this->CI->db->where($custom['field_data']['resource_id_column'], (int) $row[$custom['field_data']['resource_id_column']])->get($table)->result_array();

        $return = array();
        if (!empty($files))
        {
            $this->CI->load->library('files/files');

            foreach ($files as &$_file)
            {
                $file_id = $_file[$file_id_column];
                $file = Files::get_file($file_id);
                $file_data = array();
                if ($file['status'])
                {
                    $__file = $file['data'];

                    // If we don't have a path variable, we must have an
                    // older style image, so let's create a local file path.
                    if (!$__file->path)
                    {
                        $file_data['url'] = base_url($this->CI->config->item('files:path') . $__file->filename);
                    }
                    else
                    {
                        $file_data['url'] = str_replace('{{ url:site }}', base_url(), $__file->path);
                    }

                    $file_data['filename'] = $__file->filename;
                    $file_data['name'] = $__file->name;
                    $file_data['description'] = $__file->description;
                    $file_data['ext'] = $__file->extension;
                    $file_data['mimetype'] = $__file->mimetype;
                    $file_data['id'] = $__file->id;
                    $file_data['filesize'] = $__file->filesize;
                    $file_data['download_count'] = $__file->download_count;
                    $file_data['date_added'] = $__file->date_added;
                    $file_data['folder_id'] = $__file->folder_id;
                    $file_data['folder_name'] = $__file->folder_name;
                    $file_data['folder_slug'] = $__file->folder_slug;
                }

                $return[] = $file_data;
            }
        }


        return $return;
    }

    // ----------------------------------------------------------------------

    /**
     * Choose a folder to upload to.
     *
     * @access	public
     * @param	[string - value]
     * @return	string
     */
    public function param_folder($value = null)
    {
        // Get the folders
        $this->CI->load->model('files/file_folders_m');

        $tree = (array) $this->CI->file_folders_m->get_folders();

        if (!$tree)
        {
            return '<em>' . lang('streams:file.folder_notice') . '</em>';
        }

        $choices = array();

        foreach ($tree as $tree_item)
        {
            // We are doing this to be backwards compat
            // with PyroStreams 1.1 and below where
            // This is an array, not an object
            $tree_item = (object) $tree_item;

            $choices[$tree_item->id] = $tree_item->name;
        }

        return form_dropdown('folder', $choices, $value);
    }

    // --------------------------------------------------------------------------

    public function param_upload_url($value = null)
    {
        return form_input(array(
            'name' => 'upload_url',
            'value' => !empty($value) ? $value : site_url('admin/files/upload'),
            'type' => 'text'
        ));
    }

    // --------------------------------------------------------------------------

    public function param_create_table($value = null)
    {
        $options = array(
            0 => lang('global:no'),
            1 => lang('global:yes'),
        );

        $input = form_dropdown('create_table', $options, $value);

        return array(
            'input' => $input,
            'instructions' => $this->CI->lang->line('streams:multiple_files.instructions_create_table')
        );
    }

    // --------------------------------------------------------------------------

    public function param_new_table_name($value = null)
    {
        $input = form_input(array(
            'name' => 'new_table_name',
            'value' => $value,
            'type' => 'text'
        ));

        return array(
            'input' => $input,
            'instructions' => $this->CI->lang->line('streams:multiple_files.instructions_new_table_name')
        );
    }

    // --------------------------------------------------------------------------

    public function param_table_name($value = null)
    {
        $tables = get_instance()->db->list_tables(true);
        $tables_dropdown = array(
            '' => '-----'
        );

        foreach ($tables as $table)
        {
            $prefix = explode('_', $table);
            if ($prefix[0] !== 'core')
            {
                $tables_dropdown[$table] = $table;
            }
        }

        return array(
            'input' => form_dropdown('choice_table_name', $tables_dropdown, $value),
            'instructions' => $this->CI->lang->line('streams:multiple_files.instructions_table_name')
        );
    }

    /**
     * Data for choice. In x : X format or just X format
     *
     * @access	public
     * @param	[string - value]
     * @return	string
     */
    public function param_resource_id_column($value = null)
    {

        return form_input(array(
            'name' => 'resource_id_column',
            'value' => !empty($value) ? $value : 'resource_id',
            'type' => 'text'
        ));
    }

    // --------------------------------------------------------------------------

    /**
     * Data for choice. In x : X format or just X format
     *
     * @access	public
     * @param	[string - value]
     * @return	string
     */
    public function param_file_id_column($value = null)
    {
        return form_input(array(
            'name' => 'file_id_column',
            'value' => !empty($value) ? $value : 'file_id',
            'type' => 'text'
        ));
    }

    // --------------------------------------------------------------------------


    public function param_max_limit_files($value = null)
    {
        return form_input(array(
            'name' => 'max_limit_files',
            'value' => !empty($value) ? $value : 5,
            'type' => 'text'
        ));
    }

    // --------------------------------------------------------------------------

    private function _table_data($field)
    {
        $object = (object) array(
                'table' => (!empty($field->field_data['table_name']) ? $field->field_data['table_name'] : "{$field->stream_slug}_{$field->field_slug}"),
                'resource_id_column' => $field->field_data['resource_id_column'],
                'file_id_column' => (!empty($field->field_data['file_id_column']) ? $field->field_data['file_id_column'] : 'file_id')
        );

        if ($field->field_data['create_table'] && !empty($field->field_data['new_table_name']))
        {
            $table_name = $field->field_data['new_table_name'];


            $fields[$field->field_data['resource_id_column']] = array('type' => 'INT', 'constraint' => 11, 'null' => false);
            $fields[$field->field_data['file_id_column']] = array('type' => 'VARCHAR', 'constraint' => 200, 'null' => false);

            $ci = get_instance();

            $ci->dbforge->add_field($fields);
            $ci->dbforge->create_table($table_name, true);

            $object->table = $table_name;
        }

        return $object;
    }

    // ----------------------------------------------------------------------

    private function _clean_files($field)
    {
        $table_data = $this->_table_data($field);

        $content = Files::folder_contents($field->field_data['folder']);
        $files = $content['data']['file'];
        $valid_files = $this->CI->db->select($table_data->file_id_column . ' as id')->from($table_data->table)->get()->result();
        $valid_files_ids = array();

        if (!empty($valid_files))
        {
            foreach ($valid_files as $vf)
            {
                array_push($valid_files_ids, $vf->id);
            }
        }

        if (!empty($files))
        {
            foreach ($files as $file)
            {
                if (!in_array($file->id, $valid_files_ids))
                {
                    Files::delete_file($file->id);
                }
            }
        }
    }

    // ----------------------------------------------------------------------
}
