"""
    Copyright (c) 2016 Ad Schellevis
    All rights reserved.

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice,
     this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright
     notice, this list of conditions and the following disclaimer in the
     documentation and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
    POSSIBILITY OF SUCH DAMAGE.

    --------------------------------------------------------------------------------------
    aggregate flow data (format in parse.py) into sqlite structured container, using a table per resolution and a
    file per collection.
"""
import os
import datetime
import sqlite3


class BaseFlowAggregator(object):
    # target location ('/var/netflow/<store>.sqlite')
    target_filename = None
    # list of fields to use in this aggregate
    agg_fields = None

    def __init__(self, resolution):
        """ construct new flow sample class
        """
        self.resolution = resolution
        # target table name, data_<resolution in seconds>
        self._target_table_name = 'data_%d' % self.resolution
        self._db_connection = None
        self._update_cur = None
        self._known_targets = list()
        # construct update and insert sql statements
        tmp = 'update %s set octets = octets + :octets_consumed, packets = packets + :packets_consumed '
        tmp += 'where mtime = :mtime and %s '
        self._update_stmt = tmp % (self._target_table_name,
                                   ' and '.join(map(lambda x: '%s = :%s' % (x, x), self.agg_fields)))
        tmp = 'insert into %s (mtime, octets, packets, %s) values (:mtime, :octets_consumed, :packets_consumed, %s)'
        self._insert_stmt = tmp % (self._target_table_name,
                                   ','.join(self.agg_fields),
                                   ','.join(map(lambda x: ':%s' % x, self.agg_fields)))
        # open database
        self._open_db()
        self._fetch_known_targets()

    def _fetch_known_targets(self):
        """ read known target table names from the sqlite db
        """
        if self._db_connection is not None:
            self._known_targets = list()
            cur = self._db_connection.cursor()
            cur.execute('SELECT name FROM sqlite_master')
            for record in cur.fetchall():
                self._known_targets.append(record[0])
            cur.close()

    def _create_target_table(self):
        """ construct target aggregate table, using resulution and list of agg_fields
        """
        if self._db_connection is not None:
            # construct new aggregate table
            sql_text = list()
            sql_text.append('create table %s ( ' % self._target_table_name)
            sql_text.append('  mtime timestamp')
            for agg_field in self.agg_fields:
                sql_text.append(', %s varchar(255)' % agg_field)
            sql_text.append(',  octets numeric')
            sql_text.append(',  packets numeric')
            sql_text.append(',  primary key(mtime, %s)' % ','.join(self.agg_fields))
            sql_text.append(')')
            cur = self._db_connection.cursor()
            cur.executescript('\n'.join(sql_text))
            cur.close()
            # read table names
            self._fetch_known_targets()

    def is_db_open(self):
        """ check if target database is open
        """
        if self._db_connection is not None:
            return True
        else:
            return False

    def _open_db(self):
        """ open / create database
        """
        if self.target_filename is not None:
            # make sure the target directory exists
            target_path = os.path.dirname(self.target_filename)
            if not os.path.isdir(target_path):
                os.makedirs(target_path)
            # open sqlite database
            self._db_connection = sqlite3.connect(self.target_filename)
            # open update/insert cursor
            self._update_cur = self._db_connection.cursor()

    def commit(self):
        """ commit data
        """
        if self._db_connection is not None:
            self._db_connection.commit()

    def add(self, flow):
        """ calculate timeslices per flow depending on sample resolution
        :param flow: flow data (from parse.py)
        """
        # make sure target exists
        if self._target_table_name not in self._known_targets:
            self._create_target_table()

        # push record(s) depending on resolution
        start_time = int(flow['flow_start'] / self.resolution) * self.resolution
        while start_time <= flow['flow_end']:
            consume_start_time = max(flow['flow_start'], start_time)
            consume_end_time = min(start_time + self.resolution, flow['flow_end'])
            if flow['duration_ms'] != 0:
                consume_perc = (consume_end_time - consume_start_time) / float(flow['duration_ms'] / 1000)
            else:
                consume_perc = 1
            if self.is_db_open():
                # upsert data
                flow['octets_consumed'] = consume_perc * flow['octets']
                flow['packets_consumed'] = consume_perc * flow['packets']
                flow['mtime'] = datetime.datetime.utcfromtimestamp(start_time)
                self._update_cur.execute(self._update_stmt, flow)
                if self._update_cur.rowcount == 0:
                    self._update_cur.execute(self._insert_stmt, flow)
            # next start time
            start_time += self.resolution
