-- Runs once, when the mysql volume is first created. The test suite applies
-- database/schema.sql to this database by itself (see DatabaseTestCase), so
-- only the empty database and the grant are needed here.
CREATE DATABASE IF NOT EXISTS geo_frete_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON geo_frete_test.* TO 'geo_frete'@'%';
FLUSH PRIVILEGES;
