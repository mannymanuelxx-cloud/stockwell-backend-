<?php

class Add_password_to_users {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
    }

    public function up()
    {
        $table = $this->_lava->db->raw("SHOW TABLES LIKE 'users'")->fetch();
        if (!$table) {
            return;
        }

        $column = $this->_lava->db->raw("SHOW COLUMNS FROM users LIKE 'password'")->fetch();
        if (!$column) {
            $this->_lava->db->raw('ALTER TABLE users ADD COLUMN password VARCHAR(255) NULL');
        }
    }

    public function down()
    {
        // Keep password hashes when rolling back unrelated schema migrations.
    }
}
