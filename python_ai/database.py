<<<<<<< HEAD
import mysql.connector

DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '',
    'database': 'obeso_clinic_database'
}


def connect_db():
    return mysql.connector.connect(**DB_CONFIG)
=======
import os
import mysql.connector

def connect_db():
    return mysql.connector.connect(
        host=os.getenv("MYSQLHOST"),
        port=int(os.getenv("MYSQLPORT", 3306)),
        user=os.getenv("MYSQLUSER"),
        password=os.getenv("MYSQLPASSWORD"),
        database=os.getenv("MYSQLDATABASE")
    )
>>>>>>> e86955d7fe0aa3f343472ba5e133bc8261b4ae4d
