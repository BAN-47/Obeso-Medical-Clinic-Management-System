import mysql.connector

DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '',
    'database': 'obeso_clinic_database'
}


def connect_db():
    return mysql.connector.connect(**DB_CONFIG)