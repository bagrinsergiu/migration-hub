#!/bin/bash
# MCP DBHub wrapper script for migration database
# Database: mb-migration.cupzc9ey0cip.us-east-1.rds.amazonaws.com
# Type: MySQL
# Access: Full read-write access (no restrictions)
# Database name: MG_prepare_mapping
# Port: 3306
# User: admin

exec npx @bytebase/dbhub@latest --transport stdio --dsn "mysql://admin:Vuhodanasos2@mb-migration.cupzc9ey0cip.us-east-1.rds.amazonaws.com:3306/MG_prepare_mapping"
