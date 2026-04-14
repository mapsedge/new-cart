<?php
session_del('admin_id');
session_del('admin_username');
session_regenerate_id(true);
redirect(URL_ADMIN . '?route=login');
