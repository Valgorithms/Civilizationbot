import asyncio
import os
import psutil
import time
import signal

from operator import itemgetter

import discord

client = discord.Client()

def remove_prefix(text, prefix):
		if text.startswith(prefix): # only modify the text if it starts with the prefix
				text = text.replace(prefix, "", 1) # remove one instance of prefix
		return text

def recalculate_ranking():
	ranking = []
	ckeylist = []
	result = []
	with open("/home/1713/civ13-tdm/SQL/awards.txt", "r") as search:
		for line in search:
			line = line.rstrip()	# remove '\n' at end of line
			medal_s = 0
			duser = line.split(";")
			if duser[2] == "long service medal":
				medal_s += 0.75
			if duser[2] == "combat medical badge":
				medal_s += 2
			if duser[2] == "tank destroyer silver badge":
				medal_s += 1
			if duser[2] == "tank destroyer gold badge":
				medal_s += 2
			if duser[2] == "assault badge":
				medal_s += 1.5
			if duser[2] == "wounded badge":
				medal_s += 0.5
			if duser[2] == "wounded silver badge":
				medal_s += 0.75
			if duser[2] == "wounded gold badge":
				medal_s += 1
			if duser[2] == "iron cross 1st class":
				medal_s += 3
			if duser[2] == "iron cross 2nd class":
				medal_s += 5
			result.append(str(medal_s)+";"+duser[0])
			if not duser[0] in ckeylist:
				ckeylist.append(duser[0])
	for i in ckeylist:
		sumc = 0
		for j in result:
			sj = j.split(';')
			if sj[1]==i:
				sumc += float(sj[0])
		ranking.append([sumc,i])
	sorted_list = sorted(ranking,key=itemgetter(0),reverse=True)
	with open("ranking.txt", "w") as search:
		for i in sorted_list:
			search.write(str(i[0])+";"+i[1]+"\n")
	return

@client.event
@asyncio.coroutine
def on_ready():
	print('Logged in as')
	print(client.user.name)
	print(client.user.id)
	print('------')
	#client.loop.create_task(counting(client))


@client.event
@asyncio.coroutine
def on_message(message):
	if message.content.startswith('!s '):
		message.content = remove_prefix(message.content, '!s ')
		if message.content.startswith('ranking'):
			recalculate_ranking()
			with open("ranking.txt", "r") as search:
				topsum = 1
				for line in search:
					if topsum<=10:
						line = line.rstrip()
						topsum+=1
						sline = line.split(';')
						yield from message.channel.send("("+str(topsum-1)+"):** "+sline[1]+"** with **"+sline[0]+"** points.")
		elif message.content.startswith('rankme'):
			split_message = message.content.split("rankme ")
			ckey = ""
			medal_s = 0
			result = ""
			if len(split_message) > 1 and len(split_message[1]) > 0:
				ckey = split_message[1]
				ckey = ckey.lower()
				ckey = ckey.replace('_','')
				ckey = ckey.replace(' ','')
			with open("/home/1713/civ13-tdm/SQL/awards.txt", "r") as search:
				found = 0
				for line in search:
					line = line.rstrip()	# remove '\n' at end of line

					if ckey in line:
						found = 1
						duser = line.split(";")
						if duser[0]==ckey:
							if duser[2] == "long service medal":
								medal_s += 0.75
							if duser[2] == "combat medical badge":
								medal_s += 2
							if duser[2] == "tank destruction silver badge":
								medal_s += 1
							if duser[2] == "tank destoyer gold badge":
								medal_s += 2
							if duser[2] == "assault badge":
								medal_s += 1.5
							if duser[2] == "wounded badge":
								medal_s += 0.5
							if duser[2] == "wounded silver badge":
								medal_s += 0.75
							if duser[2] == "wounded gold badge":
								medal_s += 1
							if duser[2] == "iron cross 1st class":
								medal_s += 3
							if duser[2] == "iron cross 2nd class":
								medal_s += 5
				result = "**"+ckey+":**"+" has a total rank of "+str(medal_s)+"."
				if not found:
					yield from message.channel.send("No medals found for this ckey.")
				else:
					yield from message.channel.send(result)
		elif message.content.startswith('medals'):
			split_message = message.content.split("medals ")
			ckey = ""
			if len(split_message) > 1 and len(split_message[1]) > 0:
				ckey = split_message[1]
				ckey = ckey.lower()
				ckey = ckey.replace('_','')
				ckey = ckey.replace(' ','')

			with open("/home/1713/civ13-tdm/SQL/awards.txt", "r") as search:
				found = 0
				for line in search:
					line = line.rstrip()	# remove '\n' at end of line

					if ckey in line:
						found = 1
						duser = line.split(";")
						if duser[0]==ckey:
							medal_s = "<:long_service:705786458874707978>"
							if duser[2] == "long service medal":
								medal_s = "<:long_service:705786458874707978>"
							if duser[2] == "combat medical badge":
								medal_s = "<:combat_medical_badge:706583430141444126>"
							if duser[2] == "tank destroyer silver badge":
								medal_s = "<:tank_silver:705786458882965504>"
							if duser[2] == "tank destroyer gold badge":
								medal_s = "<:tank_gold:705787308926042112>"
							if duser[2] == "assault badge":
								medal_s = "<:assault:705786458581106772>"
							if duser[2] == "wounded badge":
								medal_s = "<:wounded:705786458677706904>"
							if duser[2] == "wounded silver badge":
								medal_s = "<:wounded_silver:705786458916651068>"
							if duser[2] == "wounded gold badge":
								medal_s = "<:wounded_gold:705786458845216848>"
							if duser[2] == "iron cross 1st class":
								medal_s = "<:iron_cross1:705786458572587109>"
							if duser[2] == "iron cross 2nd class":
								medal_s = "<:iron_cross2:705786458849673267>"
							result = "**"+duser[1]+":**"+" received "+medal_s+" **"+duser[2]+"** in *"+duser[4]+"*, "+duser[5]
							yield from message.channel.send(result)
				if not found:
					yield from message.channel.send("No medals found for this ckey.")
		elif message.content.startswith('ts'):
			split_message = message.content.split("ts ")
			if len(split_message) > 1 and len(split_message[1]) > 0:
				state = split_message[1]
				accepted = False
				for role in message.author.roles:
					if role.name == "Admiral":
						accepted = True
				if accepted:
					if state == "on":
						os.system('cd /home/1713/civ13-typespess')
						os.system('sudo git pull')
						os.system('sudo sh launch_server.sh &')
						yield from message.channel.send("Put **TypeSpess Civ13** test server on: http://civ13.com/ts")
					elif state == "off":
						pids = [pid for pid in os.listdir('/proc') if pid.isdigit()]

						for pid in pids:
							try:
								name = open(os.path.join('/proc', pid, 'cmdline'), 'r').read()
								if "index.js" in name:
									os.kill(int(pid), signal.SIGKILL)
							except IOError:
								continue
						yield from message.channel.send("**TypeSpess Civ13** test server down.")

client.run('NDcxNDAwMTY4OTczOTI2NDMw.Xr76Ew.5h-Z7OHnpZDJzgktSitCQmhMYHc')
