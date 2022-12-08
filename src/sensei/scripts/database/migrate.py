#!/usr/local/sensei/py_venv/bin/python3
from database import Database, BASE_DIR
import time
import tempfile
import os
import sqlite3
import shutil
import logging
from logging.handlers import TimedRotatingFileHandler

ts = time.time()
new_db = os.path.join(tempfile.gettempdir(), '%d.db' % ts)
db_name = ""

logger = logging.getLogger('Senseigui log')
logger.setLevel(logging.DEBUG)
handler = TimedRotatingFileHandler(BASE_DIR + '/log/active/Senseigui.log', when='midnight', interval=1, backupCount=10)
logger.addHandler(handler)
logger.info("Migrate: Starting migration of setting db")

try:
    logger.info("Migrate: Migrate: sql_file") 
    curr_db_sql_file = os.path.join(BASE_DIR, 'templates','settingsdb.sql')
    
    curr_db = os.path.join(BASE_DIR, 'userdefined', 'config', 'settings.db')
    logger.info("Migrate: Backup to current settings db %s..." % (curr_db + ".%d" % ts)) 
    shutil.copy(curr_db, curr_db + ".%d" % ts)
    sql_file = open(curr_db_sql_file)
    sql_as_string = sql_file.read()
    
    logger.info("Migrate: Create new db %s...",new_db) 
    conn = sqlite3.connect(new_db)
    conn.row_factory = lambda c, r: dict([(col[0], r[idx]) for idx, col in enumerate(c.description)])
    cursor = conn.cursor()
    logger.info("Migrate: Executing sql script...") 
    cursor.executescript(sql_as_string)
    cursor.execute('delete from policies')
    cursor.execute('SELECT version FROM sensei_version order by id desc limit 1')
    current_version = cursor.fetchone()['version']
    cursor.execute('delete from sensei_version')
    cursor.execute('delete from user_notices')
    cursor.execute('SELECT name FROM sqlite_master WHERE type=\'table\'')
    tables = cursor.fetchall()
    logger.info("Migrate: take table list...") 
    new_tables = [record['name'] for record in tables]

    with Database() as database:
        table_list = database.get_tables()
        for t in table_list:
            if t in ['sqlite_sequence']:
                continue
            col_list = database.get_columns(t)
            datas = database.get_allrecord(t)
            logger.info("Migrate:  %s table process..." % t)
            for d in datas:
                v = []
                cols = [v["name"] for v in col_list]
                for c in col_list:
                    if d[c["name"]] == None or d[c["name"]] == 'null' or d[c["name"]] == '':
                        cols.remove(c["name"])
                        continue
                    val = d[c["name"]]
                    #val = d[c["name"]] if d[c["name"]] != None else ""
                    #if val == '' or val == 'null':
                    #    val = ""
                    if c["type"].upper() in ["INT","INTEGER"]:
                        v.append(str(val))
                    else:
                        v.append("'%s'" % (val.replace("'","''") if c["type"].upper() != 'NUMERIC' else val))
                if t in new_tables:
                    insert_sql = "INSERT INTO %s(" % t
                    insert_sql += ",".join(cols)
                    insert_sql += ") VALUES ("
                    cursor.execute(insert_sql  + ",".join(v) + ");")             
        os.rename(database.file, database.file + ".%s" % ts)
        db_name = database.file
        cursor.execute("INSERT INTO sensei_version(version,creation_date) select '%s',strftime('%%s', 'now')  where (select count(*) from sensei_version where version='%s') = 0;" % (current_version,current_version))
    cursor.close()
    conn.commit()
    conn.close()
    print("db: %s" % new_db)
    #logger.info("Migrate: Remove current database...")
    #os.remove(db_name)
    if os.path.exists(db_name + "-wal"):
        logger.info("Migrate: Remove current database wal file...")
        os.remove(db_name + "-wal")
    if os.path.exists(db_name + "-shm"):
        logger.info("Migrate: Remove current database shm file...")
        os.remove(db_name + "-shm")
    logger.info("Migrate: Copying...")    
    shutil.copy(new_db, db_name)
    logger.info("Migrate: Finish...")
except Exception as e:
    logger.error("Migrate: ERROR:...%s" % e)
    print("ERROR: %s" % e)
