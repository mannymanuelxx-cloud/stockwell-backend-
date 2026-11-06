<?php

class Create_products_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('products')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'id' => [
                    'type'           => 'INT',
                    'unsigned'       => TRUE,
                    'auto_increment' => TRUE,
                    'null'           => FALSE,
                ],
                'product_name' => [
                    'type'   => 'VARCHAR',
                    'length' => 255,
                    'null'   => FALSE,
                ],
                'description' => [
                    'type'   => 'TEXT',
                    'null'   => TRUE,
                ],
                'price' => [
                    'type'    => 'DECIMAL',
                    'length'  => '10,2',
                    'null'    => FALSE,
                ],
                'quantity' => [
                    'type'    => 'INT',
                    'unsigned' => TRUE,
                    'default' => 0,
                    'null'    => FALSE,
                ],
                'created_at' => [
                    'type'    => 'DATETIME',
                    'null'    => FALSE,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
            ])
            ->add_key('id', primary: TRUE)
            ->create_table('products');
    }

    public function down()
    {
        if ($this->_lava->dbforge->table_exists('products')) {
            $this->_lava->dbforge->drop_table('products');
        }
    }
}
