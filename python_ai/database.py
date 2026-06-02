import os
import mysql.connector

DB_CONFIG = {
    'host': os.getenv('MYSQLHOST', 'localhost'),
    'port': int(os.getenv('MYSQLPORT', 3306)),
    'user': os.getenv('MYSQLUSER', 'root'),
    'password': os.getenv('MYSQLPASSWORD', ''),
    'database': os.getenv('MYSQLDATABASE', 'obeso_clinic_database')
}


def connect_db():
    return mysql.connector.connect(**DB_CONFIG)

