#!/usr/local/sensei/py_venv/bin/python3
import sys
python_version = sys.version
from base64 import b64decode
from email import generator
from email.mime.application import MIMEApplication
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from email.utils import COMMASPACE, formatdate,formataddr
from email import charset
from configparser import ConfigParser
mail_config = ConfigParser()
config = ConfigParser()
from datetime import datetime, timedelta
import logging
from logging.handlers import TimedRotatingFileHandler
import argparse
import smtplib
import ssl
import socket
import json
import os
import sqlite3
from generate_es import generate_es 
from generate_mn import generate_mn
from generate_sq import generate_sq

EASTPECT_ROOT = '/usr/local/sensei'
if 'EASTPECT_ROOT' in os.environ:
    EASTPECT_ROOT = os.environ.get('EASTPECT_ROOT')

SENSEI_CFG = os.path.join(EASTPECT_ROOT,'etc', 'eastpect.cfg')
LOG_FILE = os.path.join(EASTPECT_ROOT, 'log', 'active','schedule_reports.log')

hl = TimedRotatingFileHandler(LOG_FILE, when='W0', interval=1, backupCount=10)
logging.basicConfig(handlers=[hl], level=logging.INFO, format='[%(asctime)s][%(levelname)s][TEMP] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')
logging.info('Starting Zenarmor Schedule Report Service %s ' % datetime.now())

config.read(SENSEI_CFG)
if config.get('Database','type') == 'ES':
    dbtype = 'elasticsearch'
if config.get('Database','type') == 'MN':
    dbtype = 'mongodb'

if config.get('Database','type') == 'SQ':
    dbtype = 'sqlite'


logging.info('Report database type %s ', dbtype)

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
MAIL_CONFIG = os.path.join(BASE_DIR, 'mail.conf')
EASTPECT_DB = os.path.join(EASTPECT_ROOT, 'userdefined', 'config', 'settings.db')
REPORT_ATTACHMENT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'attachment.htm')
REPORT_BODY = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'body.htm')
DATE_STR = (datetime.now() - timedelta(hours=24)).strftime('%m.%d.%Y')
DATE_SUBJECT_STR = (datetime.now() - timedelta(hours=24)).strftime('%A, %B %d, %Y')
IS_TEST = len(sys.argv) > 1
ENABLED = False
RESULT = {}
mail_config.read(MAIL_CONFIG)
PDF = False

def hacheck():
    logging.info('HA checking')
    carp = os.popen('ifconfig | grep -c carp').read()
    if int(carp) == 0:
        return True
    master = os.popen('ifconfig | grep -i carp | grep -c MASTER').read()
    if int(master) > 0:
        return True
    return False

conn = sqlite3.connect(EASTPECT_DB)
conn.row_factory = sqlite3.Row
cur_p = conn.cursor()
nosslverify = False
if len(sys.argv) > 1:
    try:
        logging.info('Mode is Test')
        parser = argparse.ArgumentParser()
        parser.add_argument('-b', '--pdf')
        parser.add_argument('-S', '--server')
        parser.add_argument('-P', '--port')
        parser.add_argument('-s', '--secured', type=str, default='false')
        parser.add_argument('-u', '--username', type=str, default='')
        parser.add_argument('-p', '--password', type=str, default='')
        parser.add_argument('-f', '--sender', type=str, default='autoreports@sunnyvalley.io')
        parser.add_argument('-t', '--to')
        parser.add_argument('-v', '--nosslverify', type=str, default='false')
        args = parser.parse_args()
        if args.server is None:
            RESULT['successful'] = False
            RESULT['message'] = 'SMTP server must be defined!'
            IS_TEST = False
            logging.info('SMTP server must be defined!')
        else:
            server = args.server
            port = int(args.port)
            secured = args.secured
            username = args.username
            password = args.password
            from_to = args.sender
            send_to = args.to.split(',')
            nosslverify = args.nosslverify == 'true'
            PDF = args.pdf == 'true'
    except Exception as e:
        RESULT['successful'] = False
        RESULT['message'] = 'Invalid Configuration! Probably some parameters are missing!'
        logging.info('Invalid Configuration! Probably some parameters are missing! %s' % e)
        IS_TEST = False
elif os.path.exists(MAIL_CONFIG):
    logging.info('Mode is Live')
    if mail_config.has_section('general'):
        ENABLED = mail_config.get('general', 'enabled') == 'true'
        PDF = mail_config.get('general', 'Pdf') == 'true'
        server = mail_config.get('general', 'SMTPHost')
        port = int(mail_config.get('general', 'SMTPPort'))
        secured = mail_config.get('general', 'Secured')
        nosslverify = mail_config.get('general', 'nosslverify') == 'true'
        username = mail_config.get('general', 'Username')
        password = b64decode(mail_config.get('general', 'Password')[4:None]).decode('utf-8')
        from_to = mail_config.get('general', 'FromEmail')
        send_to = mail_config.get('general', 'ToEmail').split(',')
        if not ENABLED:
            RESULT['successful'] = False
            RESULT['message'] = 'Scheduled reporting is disabled!'
            logging.info('Scheduled reporting is disabled!')
    else:
        RESULT['successful'] = False
        RESULT['message'] = 'Empty Configuration!'
        logging.info('Empty Configuration!')
else:
    RESULT['successful'] = False
    RESULT['message'] = 'No Configuration File Found!'
    logging.info('No Configuration File Found!')

if config.get('Database','type') == 'MN':
    generate_mn(PDF,logging)

if config.get('Database','type') == 'ES':
    generate_es(PDF,logging)    

if config.get('Database','type') == 'SQ':
    generate_sq(config.get('Database','dbpath'),PDF,logging)    

if PDF:
    REPORT_ATTACHMENT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'attachment.pdf')


if IS_TEST or ENABLED:
    try:
        msg = MIMEMultipart('related')
        from_email = from_to
        if len(from_to) == 0:
            from_email = username[:username.index('@') if '@' in username else len(username)]
        
        if len(username) == 0 and len(from_to) == 0:
            from_email = 'Zenamor'
            username = 'zenarmor@localhost'
        
        if len(username) > 0 and '@' in username:
            from_email = formataddr((from_email, username))
        else:
            from_email = formataddr((from_to, from_to))
        
        if '@' not in username and len(from_to)>0:
            from_email = from_to

        msg['From'] = from_email    
        msg['To'] = COMMASPACE.join(send_to)
        msg['Date'] = formatdate(localtime=True)
        msg['Subject'] = 'Zenarmor Daily Report for %s (%s)' % (mail_config.get('general', 'HostName'), DATE_SUBJECT_STR)

#        if IS_TEST:
#            msg.attach(MIMEText('This is a test message.'))
#        else:
        if os.path.exists(REPORT_ATTACHMENT) and os.path.exists(REPORT_BODY):
            with open(REPORT_BODY, 'r') as f:
                part = MIMEText(f.read(), 'html')
                msg.attach(part)

            if PDF:
                with open(REPORT_ATTACHMENT, 'rb') as f:
                    part = MIMEApplication(f.read(), Name=os.path.basename('Sensei-Daily-Report-%s-%s.%s' % (mail_config.get('general', 'HostName'), DATE_STR,'pdf')))
                    part.add_header('Content-Disposition', 'attachment; filename="Sensei-Daily-Report-%s-%s.%s"' % (mail_config.get('general', 'HostName'), DATE_STR,'pdf'))
                    part.add_header('Content-ID', '<attach1>')
                    msg.attach(part)
            else:
                with open(REPORT_ATTACHMENT, 'r') as f:
                    part = MIMEText(f.read(), 'html')
                    part.add_header('Content-Disposition', 'attachment; filename="Sensei-Daily-Report-%s-%s.%s"' % (mail_config.get('general', 'HostName'), DATE_STR,'html'))
                    msg.attach(part)

        else:
            rsp = 'Scheduled reports could not be generated. Probably %s service is not running or not working properly. ' % dbtype
            rsp += 'Please check %s service manually.' % dbtype
            msg.attach(MIMEText(rsp))
    
        outfile_name = os.path.join("/", "tmp", "report_email.eml")
        with open(outfile_name, 'w') as outfile:
            outfile.write(msg.as_string())

        if secured=='SSL':
            #context = ssl.SSLContext(ssl.PROTOCOL_SSLv23)
            #context.options |= ssl.OP_NO_SSLv2
            if  nosslverify:
                context = ssl._create_unverified_context()  
            else:
                context = ssl.create_default_context()
            smtp = smtplib.SMTP_SSL(server, port, timeout=30, context=context)
            # smtp = smtplib.SMTP_SSL(server, port, timeout=10)

        else:
            smtp = smtplib.SMTP(server, port, timeout=30)
            smtp.ehlo()
            if secured=='TLS':
                if nosslverify:
                    context = ssl._create_unverified_context()
                else:
                    context = ssl.create_default_context()
                      
                smtp.starttls(context=context)
        smtp.ehlo()

        if password:
            smtp.login(username, password)

        # hacheck if 
        if hacheck():
            smtp.sendmail(username, send_to, msg.as_string())
            smtp.close()
        else:
            RESULT['successful'] = False
            RESULT['message'] = 'HA is passive,not send'
            logging.info('HA is passive,not send')


        RESULT['successful'] = True
        RESULT['message'] = 'Mail has been send successfully!'
        logging.info('Mail has been send successfully!')

        outfile_name = os.path.join("/", "tmp", "report_email.eml")
        with open(outfile_name, 'w') as outfile:
            outfile.write(msg.as_string())

    except smtplib.SMTPException as error:
        RESULT['successful'] = False
        RESULT['message'] = 'Smtp :' + str(error)
        logging.error(RESULT['message'])

    except socket.error as error:
        RESULT['successful'] = False
        if error.strerror is None:
            RESULT['message'] = 'Socket Time Out!'
        else:
            RESULT['message'] = 'Socket: ' + error.strerror
        logging.error(RESULT['message'])

    except Exception as exc:
        RESULT['successful'] = False
        RESULT['message'] = 'General: ' + str(exc)
        logging.error(RESULT['message'])

if not IS_TEST and not RESULT['successful']:
        cur_p.execute("insert into user_notices(notice_name,notice) values(:notice_name,:notice)",
        {'notice_name': 'schedule Reports',
         'notice': '<p><strong>Failed sending scheduled reports:</strong></p><p>Send e-Mail failed. Please re-check your scheduled reports configuration.</p><p><strong>' + RESULT['message'] + '</strong></p>'
        })
        conn.commit()
        logging.info('insert notice info')

print(json.dumps(RESULT))
