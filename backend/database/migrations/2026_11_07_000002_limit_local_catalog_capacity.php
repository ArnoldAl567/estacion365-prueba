<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_local_catalog_capacity() RETURNS trigger AS $$
            BEGIN
                PERFORM pg_advisory_xact_lock(3652028);
                IF NOT EXISTS (SELECT 1 FROM menu_products WHERE item_key = NEW.item_key)
                    AND (SELECT count(*) FROM menu_products) >= 250 THEN
                    RAISE EXCEPTION 'La carta admite un máximo de 250 productos.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER local_catalog_capacity BEFORE INSERT ON menu_products
                FOR EACH ROW EXECUTE FUNCTION enforce_local_catalog_capacity();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS local_catalog_capacity ON menu_products; DROP FUNCTION IF EXISTS enforce_local_catalog_capacity();');
    }
};
