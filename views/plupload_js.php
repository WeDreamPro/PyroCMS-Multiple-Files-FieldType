<div id="upload-container">
    <div id="drop-target">
        <div class="drop-area" style="display: none;">
            <span><?php echo lang('streams:multiple_files.help_draganddrop') ?></span>
            <span style="display: none;"><?php echo lang('streams:multiple_files.drop_images_here') ?></span>
        </div>
        <div class="no-drop-area" style="display: none; max-width: 40%;">
            <a href="#" class="btn blue"><?php echo lang('streams:multiple_files.select_files'); ?></a>
        </div>
    </div>
</div>

<ul id="multiple-files-list"><!-- Files --></ul>

<script id="file-template" type="text/x-handlebars-template">
    <li id="file-{{id}}" class="file-container {{#unless is_new}} load {{/unless}}">
    <div class="name">
    {{#if is_new}} 
    {{name}} 
    {{else}}
    <a href="{{url}}" target="_blank">{{name}}</a>
    {{/if}}
    </div>
    <div class="size">{{{bytesFormat size}}}</div>
    <div class="progress">
    <div class="progress-bar">
    <div class="bar"></div>
    </div>
    </div>
    <div class="delete-file">
    <a href="#"><i class="icon-remove"></i></a>
    </div>
    <input class="file-input" type="hidden" name="files[]" value="{{id}}" />
    </li>
</script>




<script>
    (function($) {
        Handlebars.registerHelper("bytesFormat", function(bytes) {
            var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            if (bytes === 0 || typeof bytes === 'undefined' || !bytes) {
                return '<em>No disponible</em>';
            }
            var i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)));
            return Math.round(bytes / Math.pow(1024, i), 2) + ' ' + sizes[i];
        });

        $(function() {
            var uploader = new plupload.Uploader({
                runtimes: 'gears,html5,flash,html4,silverlight,browserplus',
                browse_button: 'drop-target',
                drop_element: 'drop-target',
                container: 'upload-container',
                max_file_size: '<?= Settings::get('files_upload_limit') ?>mb',
                url: <?= json_encode($upload_url) ?>,
                flash_swf_url: '<?= $field_path ?>js/plupload.flash.swf',
                silverlight_xap_url: '<?= $field_path ?>js/plupload.silverlight.xap',
                filters: [
                    {title: "All files", extensions: "*"}
                ],
                multipart_params: <?= json_encode($multipart_params) ?>
            });

            var nativeFiles = {},
                isHTML5 = false,
                $file_template = Handlebars.compile($('#file-template').html()),
                $files_list = $('#multiple-files-list'),
                entry_is_new = <?= json_encode($is_new) ?>,
                files = <?= json_encode($files) ?>;

            uploader.bind('PostInit', function() {
                isHTML5 = uploader.runtime === "html5";
                if (isHTML5) {
                    var inputFile = document.getElementById(uploader.id + '_html5');
                    var oldFunction = inputFile.onchange;

                    inputFile.onchange = function() {
                        nativeFiles = this.files;
                        oldFunction.call(inputFile);
                    }

                    $('#drop-target').addClass('html5').on({
                        drop: function(e) {
                            var files = e.originalEvent.dataTransfer.files;
                            nativeFiles = files;

                            return $(this).removeClass('dragenter').find('.drop-area span:last').hide().prev().show();
                        }
                    });

                    $('body').on({
                        dragenter: function() {
                            return $('#drop-target').addClass('dragenter').find('.drop-area span:first').hide().next().show();
                        },
                        dragleave: function() {
                            return $('#drop-target').removeClass('dragenter').find('.drop-area span:last').hide().prev().show();
                        }
                    });

                    $('.drop-area').show();
                } else {
                    $('.no-drop-area').show();
                }
            });

            uploader.bind('Init', function(up, params) {

            });

            uploader.init();

            uploader.bind('FilesAdded', function(up, files) {

                $.each(files, function(i, file) {
                    add_file({
                        id: file.id,
                        name: file.name,
                        size: file.size,
                        is_new: true
                    });
                });

                uploader.start();

                up.refresh();
            });

            uploader.bind('UploadProgress', function(up, file) {
                $file(file.id).find('.bar').css({width: file.percent + '%'});

                /* Prevent close while upload */
                $(window).on('beforeunload', function() {
                    return 'Hay una subida en progreso, si recarga o sale de la página podría interrumpir el proceso.';
                });
            });

            uploader.bind('Error', function(up) {
                $('<div class="alert error" style="margin-top: 1em;"><p><?= lang('streams:multiple_files.adding_error') ?></p></div>').insertAfter('#upload-container');
                up.refresh();
            });

            uploader.bind('FileUploaded', function(up, file, info) {
                var response = JSON.parse(info.response);
                if (response.status === false) {
                    /* Adding error message from uploader */
                    $('<div class="alert error" style="margin-top: 1em;"><p>' + file.name + ':' + response.message + '</p></div>').insertAfter('#upload-container');
                    /* Delete corrupt file from list */
                    setTimeout(function() {
                        $('#file-' + file.id).fadeOut('slow', function() {
                            return $(this).remove();
                        });
                    }, 2000);

                    up.refresh();
                } else {
                    var anchor = $('<a />', {
                        href: response.data.path.replace("{{ url:site }}", SITE_URL)
                    });
                    $file(file.id).addClass('load').find('.file-input').val(response.data.id);
                    $file(file.id).find('.name').wrapInner(anchor);
                }
                /* Off: Prevent close while upload */
                $(window).off('beforeunload');
            });


            /* Private methods */

            function $file(id) {
                return $('#file-' + id);
            }

            function add_file(data) {
                return $files_list.append($file_template(data));
            }

            if (entry_is_new === false && files) {
                for (var i in files) {
                    add_file(files[i]);
                }
            }

            /* Events! */

            $(document).on('click', '.delete-file a', function(e) {
                var $this = $(this),
                    $parent = $this.parents('.file-container'),
                    file_id = $parent.find('input.file-input').val();

                if (confirm(pyro.lang.dialog_message)) {
                    $.post(SITE_URL + 'admin/files/delete_file', {file_id: file_id}, function(json) {
                        if (json.status === true) {
                            $parent.fadeOut(function() {
                                return $(this).remove();
                            });
                        } else {
                            alert(json.message);
                        }
                    }, 'json');
                }

                return e.preventDefault();
            });
        });
    })(jQuery);


</script>