import sys
import requests
import json
from requests.auth import HTTPBasicAuth
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)


# uri of firewall which run api
api_host = 'https://192.168.122.101:8090/'
# please set this username which take from sunnyvalley cloud.
api_user = 'testuser'
# please take api key from cloud 
api_key = 'uBHElPyuMjWy74f1HeArB1rtMf5krICmlVbVSNGRbdI'


headers = {'Content-Type': 'application/json; charset=UTF-8','apikey': api_key}

def printHelp():
    print('''Parameter used...
    ./zenapi.py policy list
    ./zenapi.py policy addip4 10.0.0.10 policy_name
    ./zenapi.py policy removeip 10.0.0.10 policy_name
    ./zenapi.py engine setbypass {true/false}
    ./zenapi.py report detectedhost
    ./zenapi.py ips list
    ./zenapi.py ips add ip 192.168.122.138 Malware+ message detail
    ./zenapi.py ips add filemd5 hash Malware+ message detail
    ./zenapi.py ips del [192.168.122.138 or hash]
    ./zenapi.py ips apply
    ''')

if len(sys.argv)>1 and sys.argv[1] == '-h':
    printHelp()


# active policy list.
def listpolicy():
    try:
        resp = requests.get('%spolicies?user=%s' % (api_host,api_user), headers=headers,timeout=30,verify=False)
        if resp.status_code > 199 and resp.status_code<300:
            resp = resp.json()
            arr = json.loads(resp["Body"])    
            policy_list = [ {'id': x['id'],'name':x['name']} for x in arr]
            return policy_list
        else:
            print("List Policy Error: %s" % resp.text)
            return []
    except Exception as e:
        print('Listpolicy Exception: %s' % str(e))
        return []
    
# engine set by pass mode , if it is true, engine not blocked connection ,
def setbypass(status):
    try:
        print("Url ==>  ",'%sbypassengine?user=%s&status=%s' % (api_host,api_user,status))
        resp = requests.post('%sbypassengine?user=%s&status=%s' % (api_host,api_user,status), headers=headers,timeout=30,verify=False)
        if resp.status_code > 199 and resp.status_code<300:
            resp = resp.json()
            arr = json.loads(resp["Body"])
            if arr['error'] == False:
                print('Process Successed')
                return True
            if arr['error'] == True:
                print('Error Bypass Engine Request: %s' % arr['message'])
                return False
    except Exception as e:
        print('Bypass Exception: %s' % str(e))
        return False

# add / remove ip from policy network list
def addBlock(policy_id,policy_name,action,val):
    try:
        print("Url ==>  ",'%supdatepolicy?user=%s&policy_id=%s&policy_name=%s&action=%s&item_type=ip&value=%s' % (api_host,api_user,policy_id,policy_name,action,val))
        resp = requests.post('%supdatepolicy?user=%s&policy_id=%s&policy_name=%s&action=%s&item_type=ip&value=%s' % (api_host,api_user,policy_id,policy_name,action,val), headers=headers,timeout=30,verify=False)
        if resp.status_code > 199 and resp.status_code<300:
            resp = resp.json()
            arr = json.loads(resp["Body"])
            print('Process Successed. Networks[%s]' %  arr[0]['networks'])
            return True
        else:
            resp = resp.json()
            arr = json.loads(resp["Body"])
            print('%s Could not success. Response: %s' % (action,arr['message']))
            return False
    except Exception as e:
        print('AddBlock Exception: %s' % str(e))
        return False

# Threat host data.
def threatData(): 
    try:
        print("Url ==>  ",'%stopdetectedthreathosts?user=%s' % (api_host,api_user))
        resp = requests.get('%stopdetectedthreathosts?user=%s' % (api_host,api_user), headers=headers,timeout=30,verify=False)
        if resp.status_code > 199 and resp.status_code<300:
            resp = resp.json()
            arr = json.loads(resp["Body"])
            if 'labels' in arr:
                print("Ip or Hostname: %s" % arr['labels'])
            if 'datasets' in arr and type(arr['datasets']) == list and len(arr['datasets'])>0:    
                print("Count: %s " % arr['datasets'][0]['data'])
            return True
        else:
            resp = resp.json()
            print('Process Not Successed.',resp)
            arr = json.loads(resp["Body"])
            print('Threat Data Response: %s' % arr['message'])
            return False
    except Exception as e:
        print('threatData Exception: %s' % str(e))
        return False

# ips signature.
def ipsSignature(data): 
    try:
        print("Url ==>  ",'%sipssignature?user=%s' % (api_host,api_user))
        resp = requests.post('%sipssignature?user=%s' % (api_host,api_user), headers=headers,data=json.dumps(data),timeout=30,verify=False)
        if resp.status_code > 199 and resp.status_code<300:
            resp = resp.json()
            return resp["Body"]
        else:
            resp = resp.json()
            arr = json.loads(resp["Body"])
            print('IPS Response: %s' % arr['message'])
            return arr
    except Exception as e:
        print('IPS Exception: %s' % str(e))
        return False

    
# is policy name exists in policy list.
def checkPolicy(policy_name):
    policy_list = listpolicy()
    t = [x for x in policy_list if str(x['name']).lower() == policy_name.lower()]
    if len(t) == 0:
        print("Policy %s not found." % policy_name)
        exit(1)
    return t[0]    
    
    
    
    
if len(sys.argv)>2 and sys.argv[1] == 'policy':
    policy_list = listpolicy()
    if sys.argv[2] == 'list':
        print('Policies: %s' % [x['name'] for x in policy_list])
        exit(0)
        
    if sys.argv[2] == 'addip4':
        if len(sys.argv) != 5:
            print("Wrong parameters list...")
            exit(1)
        t = checkPolicy(sys.argv[4])    
        addBlock(t['id'],t['name'],'add',sys.argv[3])
        exit(0)
        
    if sys.argv[2] == 'removeip':
        if len(sys.argv) != 5:
            print("Wrong parameters list...")
            exit(1)
        t = checkPolicy(sys.argv[4])    
        addBlock(t['id'],t['name'],'remove',sys.argv[3])
        exit(0)
        
if len(sys.argv)>3 and sys.argv[1] == 'engine':
    if sys.argv[2] == 'setbypass' and sys.argv[3] in ["true","false"]:
        setbypass(sys.argv[3]) 
        exit(0)

if len(sys.argv)>2 and sys.argv[1] == 'report':
    if sys.argv[2] == 'detectedhost':
        threatData() 
        exit(0)

if len(sys.argv)>2 and sys.argv[1] == 'ips':
    if sys.argv[2] == 'list':
        lines = ipsSignature({"action":"list"})
        lines = json.loads(lines)
        for line in lines:
            print(line)
        exit(0)
    if sys.argv[2] == 'add' and len(sys.argv)>4:
        resp = ipsSignature({"action":"add","type": sys.argv[3] ,"data": sys.argv[4],"category": sys.argv[5], "message": sys.argv[6] if len(sys.argv)>6 else '',"detail": sys.argv[7] if len(sys.argv)>7 else ''})
        print('IPS Added: %s' % resp )
        exit(0)

    if sys.argv[2] == 'del' and len(sys.argv)>3:
        response = ipsSignature({"action":"del","data": sys.argv[3]})
        print('IPS Deleted: %s' % response )
        exit(0)

    if sys.argv[2] == 'apply':
        response = ipsSignature({"action":"apply"})
        print('IPS Apply Response: %s' % response )
        exit(0)
    
            
printHelp()