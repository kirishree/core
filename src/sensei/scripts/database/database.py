#!/usr/local/sensei/py_venv/bin/python3
import sqlite3
import os

BASE_DIR = '/usr/local/sensei'

if "EASTPECT_ROOT" in os.environ:
    BASE_DIR = os.environ.get('EASTPECT_ROOT')

class Database:
    def __init__(self):
        self.file = os.path.join(BASE_DIR, 'userdefined', 'config', 'settings.db')
        self.conn = sqlite3.connect(self.file)
        self.conn.row_factory = lambda c, r: dict([(col[0], r[idx]) for idx, col in enumerate(c.description)])
        self.cursor = self.conn.cursor()

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc_value, traceback):
        if self.conn:
            self.conn.commit()
            self.cursor.close()
            self.conn.close()

    def execute(self, sql):
        return self.cursor.execute(sql)

    def get_tables(self):
        self.execute('SELECT name FROM sqlite_master WHERE type=\'table\'')
        tables = self.cursor.fetchall()
        return [record['name'] for record in tables]

    def get_columns(self, table):
        self.execute("SELECT name, type FROM pragma_table_info('%s')" % table)
        return self.cursor.fetchall()

    def add_column(self, table, column):
        self.execute('ALTER table %s add column %s %s' % (table, column['name'], column['type']))

    def get_allrecord(self, table):
        self.cursor.execute('SELECT * FROM %s' % table)
        return self.cursor.fetchall()

    def get_record(self, table, column, value):
        self.cursor.execute('SELECT * FROM %s WHERE %s=:value' % (table, column), {
            'value': value
        })
        return self.cursor.fetchone()
