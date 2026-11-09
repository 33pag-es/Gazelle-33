#!/bin/bash

# The MySQL entrypoint sources this file, so do not change shell options.

export MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD must be set}"

mysql --protocol=socket -uroot <<SQL
CREATE USER IF NOT EXISTS '${MYSQL_RO_USER:?}'@'%' IDENTIFIED BY '${MYSQL_RO_PASSWORD:?}';
GRANT SELECT, SHOW VIEW ON \`${MYSQL_DATABASE:?}\`.* TO '${MYSQL_RO_USER}'@'%';

CREATE USER IF NOT EXISTS '${MYSQL_PHINX_USER:?}'@'%' IDENTIFIED BY '${MYSQL_PHINX_PASSWORD:?}';
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_PHINX_USER}'@'%';

GRANT SELECT ON performance_schema.table_io_waits_summary_by_index_usage TO '${MYSQL_USER:?}'@'%';
GRANT SELECT ON performance_schema.table_io_waits_summary_by_table TO '${MYSQL_USER}'@'%';
GRANT SELECT ON sys.schema_redundant_indexes TO '${MYSQL_USER}'@'%';
GRANT SELECT ON sys.schema_unused_indexes TO '${MYSQL_USER}'@'%';
GRANT SELECT ON sys.x\$schema_flattened_keys TO '${MYSQL_USER}'@'%';

GRANT SELECT ON performance_schema.table_io_waits_summary_by_index_usage TO '${MYSQL_RO_USER}'@'%';
GRANT SELECT ON performance_schema.table_io_waits_summary_by_table TO '${MYSQL_RO_USER}'@'%';
GRANT SELECT ON sys.schema_redundant_indexes TO '${MYSQL_RO_USER}'@'%';
GRANT SELECT ON sys.schema_unused_indexes TO '${MYSQL_RO_USER}'@'%';
GRANT SELECT ON sys.x\$schema_flattened_keys TO '${MYSQL_RO_USER}'@'%';
SQL
