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

# Ocelot is optional. It uses a native-password account because its
# libmysqlclient build does not handle caching_sha2_password reliably.
# Grants are per table, matching the statements in Ocelot's src/db.cpp: it
# reads five tables, writes five, and TRUNCATEs xbt_files_users at startup
# (MySQL requires DROP for that). It never needs UPDATE on users_main.
# This script runs before migrations create the tables, and MySQL only
# accepts a table grant on a missing table when it includes CREATE; CREATE on
# an existing table name is inert. Keep this account dedicated to Ocelot.
if [ -n "${OCELOT_MYSQL_PASSWORD:-}" ]; then
    ocelot_db=${MYSQL_DATABASE}
    ocelot_who="'${OCELOT_MYSQL_USER:?}'@'%'"
    mysql --protocol=socket -uroot <<SQL
CREATE USER IF NOT EXISTS ${ocelot_who} IDENTIFIED WITH mysql_native_password BY '${OCELOT_MYSQL_PASSWORD}';
GRANT CREATE, SELECT ON \`${ocelot_db}\`.torrents TO ${ocelot_who};
GRANT CREATE, SELECT ON \`${ocelot_db}\`.users_main TO ${ocelot_who};
GRANT CREATE, SELECT ON \`${ocelot_db}\`.xbt_client_whitelist TO ${ocelot_who};
GRANT CREATE, SELECT, INSERT, UPDATE ON \`${ocelot_db}\`.torrents_leech_stats TO ${ocelot_who};
GRANT CREATE, SELECT, INSERT, UPDATE ON \`${ocelot_db}\`.users_leech_stats TO ${ocelot_who};
GRANT CREATE, SELECT, INSERT, UPDATE ON \`${ocelot_db}\`.users_freeleeches TO ${ocelot_who};
GRANT CREATE, SELECT, INSERT ON \`${ocelot_db}\`.xbt_snatched TO ${ocelot_who};
GRANT CREATE, SELECT, INSERT, UPDATE, DROP ON \`${ocelot_db}\`.xbt_files_users TO ${ocelot_who};
SQL
    unset ocelot_db ocelot_who
fi
