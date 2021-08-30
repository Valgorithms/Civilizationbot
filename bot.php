from __future__ import print_function
#from googletrans import Translator

import asyncio
import codecs
import random
import os
import psutil
import time
import socket
import subprocess
from operator import itemgetter
from pathlib import Path
from datetime import datetime

import discord

client = discord.Client()

def portIsAvailable(port):

	s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)

	try:
		s.bind(("127.0.0.1", port))
	except socket.error as e:
		#if e.errno == 98:
			#print("Port is already in use")
		#else:
			# something else raised the socket.error exception
			#print("Error: " + e)
		s.close()
		return False
	else:
		s.close()
		return True

	s.close()
	return False

def remove_prefix(text, prefix):
		if text.startswith(prefix): # only modify the text if it starts with the prefix
				text = text.replace(prefix, "", 1) # remove one instance of prefix
		return text

def my_message(msg):
		return msg.author == client.user

def search_players(ckey):
	if ckey in open('/home/1713/civ13-rp/SQL/playerlogs.txt').read():
		return ckey
	else:
		return "None"

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
	channelname = ""
	for channel in client.get_all_channels():
		if channel.name.lower() == "ooc-nomads":
			channelname = channel
			filepath = '/home/1713/civ13-rp/ooc.log'
			with open(filepath, 'r+', encoding='utf-8') as fp:
				lines = fp.readlines()
				fp.truncate(0)
				for line in lines:
					line = line.strip('\n')
					yield from channelname.send(line)
					
		if channel.name.lower() == "ahelp-nomads":
			channelname = channel
			filepath = '/home/1713/civ13-rp/admin.log'
			with open(filepath, 'r+', encoding='utf-8') as fp:
				lines = fp.readlines()
				fp.truncate(0)
				for line in lines:
					line = line.strip('\n')
					yield from channelname.send(line)

		if channel.name.lower() == "ooc-tdm":
			channelname = channel
			filepath = '/home/1713/civ13-tdm/ooc.log'
			with open(filepath, 'r+', encoding='utf-8') as fp:
				lines = fp.readlines()
				fp.truncate(0)
				for line in lines:
					line = line.strip('\n')
					yield from channelname.send(line)
		if channel.name.lower() == "ahelp-tdm":
			channelname = channel
			filepath = '/home/1713/civ13-tdm/admin.log'
			with open(filepath, 'r+', encoding='utf-8') as fp:
				lines = fp.readlines()
				fp.truncate(0)
				for line in lines:
					line = line.strip('\n')
					yield from channelname.send(line)

	if message.content.startswith('!s '):
		message.content = remove_prefix(message.content, '!s ')
		if message.content.lower().startswith('insult'):
			split_message = message.content.split("insult ")
			if len(split_message) > 1 and len(split_message[1]) > 0:
				ckey = split_message[1]
				insults = open('insult.txt').read().splitlines()
				insult = random.choice(insults)
				yield from message.channel.send("{}, {}".format(ckey,insult))
		elif message.content.lower().startswith('ooc '):
			if message.channel.name.lower() == "ooc-nomads":
					message.content = remove_prefix(message.content, 'ooc ')
					with open("/home/1713/civ13-rp/SQL/discord2ooc.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(message.content))
						myfile.write("\n")
			if message.channel.name.lower() == "ooc-tdm":
					message.content = remove_prefix(message.content, 'ooc ')
					with open("/home/1713/civ13-tdm/SQL/discord2ooc.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(message.content))
						myfile.write("\n")
		elif message.content.lower().startswith('asay '):
			if message.channel.name.lower() == "ahelp-nomads":
					message.content = remove_prefix(message.content, 'asay ')
					with open("/home/1713/civ13-rp/SQL/discord2admin.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(message.content))
						myfile.write("\n")
			if message.channel.name.lower() == "ahelp-tdm":
					message.content = remove_prefix(message.content, 'asay ')
					with open("/home/1713/civ13-tdm/SQL/discord2admin.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(message.content))
						myfile.write("\n")
		elif message.content.lower().startswith('dm '):
			if message.channel.name.lower() == "ahelp-nomads":
					message.content = remove_prefix(message.content, 'dm ')
					split_message = message.content.split(": ")
					with open("/home/1713/civ13-rp/SQL/discord2dm.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(split_message[0])+":::"+str(split_message[1]))
						myfile.write("\n")
			if message.channel.name.lower() == "ahelp-tdm":
					message.content = remove_prefix(message.content, 'dm ')
					split_message = message.content.split(": ")
					with open("/home/1713/civ13-tdm/SQL/discord2dm.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(split_message[0])+":::"+str(split_message[1]))
						myfile.write("\n")
		elif message.content.lower().startswith('pm '):
			if message.channel.name.lower() == "ahelp-nomads":
					message.content = remove_prefix(message.content, 'pm ')
					split_message = message.content.split(": ")
					with open("/home/1713/civ13-rp/SQL/discord2dm.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(split_message[0])+":::"+str(split_message[1]))
						myfile.write("\n")
			if message.channel.name.lower() == "ahelp-tdm":
					message.content = remove_prefix(message.content, 'pm ')
					split_message = message.content.split(": ")
					with open("/home/1713/civ13-tdm/SQL/discord2dm.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(split_message[0])+":::"+str(split_message[1]))
						myfile.write("\n")
		elif message.content.lower().startswith('ban '):
					message.content = remove_prefix(message.content, 'ban ')
					split_message = message.content.split("; ")
					if message.content.find('Byond account too new, appeal on our discord') == -1:
						with open("/home/1713/civ13-rp/SQL/discord2ban.txt", "a") as myfile:
							myfile.write(str(message.author)+":::"+str(split_message[0])+":::"+str(split_message[1])+":::"+str(split_message[2]))
							myfile.write("\n")
						with open("/home/1713/civ13-tdm/SQL/discord2ban.txt", "a") as myfile:
							myfile.write(str(message.author)+":::"+str(split_message[0])+":::"+str(split_message[1])+":::"+str(split_message[2]))
							myfile.write("\n")
					result = "**{}** banned **{}** for **{}** with the reason **{}**.".format(message.author,str(split_message[0]),str(split_message[1]),str(split_message[2]))
					yield from message.channel.send(result)
		elif message.content.lower().startswith('unban '):
					message.content = remove_prefix(message.content, 'unban ')
					split_message = message.content.split("; ")
					with open("/home/1713/civ13-rp/SQL/discord2unban.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(split_message[0]))
						myfile.write("\n")
					with open("/home/1713/civ13-tdm/SQL/discord2unban.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(split_message[0]))
						myfile.write("\n")
					result = "**{}** unbanned **{}**.".format(message.author,str(split_message[0]))

					list1 = "/home/1713/civ13-rp/SQL/bans.txt"
					open(list1, "a").close()
					f = open(list1, "r")
					lines = f.readlines()
					f.close()
					f = open(list1, "w")
					for line in lines:
						if not str(split_message[0]) in line:
							f.write(line)
					f.close()
					
					list2 = "/home/1713/civ13-tdm/SQL/bans.txt"
					open(list2, "a").close()
					f2 = open(list2, "r")
					lines2 = f2.readlines()
					f2.close()
					f2 = open(list2, "w")
					for line2 in lines2:
						if not str(split_message[0]) in line2:
							f2.write(line2)
					f2.close()
					yield from message.channel.send(result)
		elif message.content.startswith('cpu'):
			CPU_Pct= str(psutil.cpu_percent())
			yield from message.channel.send('CPU Usage: ' + CPU_Pct +"%")
		elif message.content.startswith('help'):
			yield from message.channel.send('**List of Commands**: bancheck, insult, cpu, ping, (un)whitelistme, rankme, ranking. **Staff only**: ban, hostciv, killciv, restartciv, mapswap, hosttdm, killtdm, restarttdm, tdmmapswap')
		# whitelist
		elif message.content.startswith('whitelistme'):
			split_message = message.content.split("whitelistme ")
			if len(split_message) > 1 and len(split_message[1]) > 0:
				ckey = split_message[1]
				accepted = False
				for role in message.author.roles:
					if role.name == "Admiral" or role.name == "Captain" or role.name == "Lieutenant" or role.name == "Brother At Arms" or role.name == "Knight":
						accepted = True
				if accepted:

					whitelist = "/home/1713/civ13-rp/SQL/whitelist.txt"

					open(whitelist, "a").close()

					with open(whitelist, "r") as search:
						for line in search:
							line = line.rstrip()	# remove '\n' at end of line
							if line == ckey+"="+str(message.author):
								yield from message.channel.send("{} is already in the whitelist!".format(ckey))

							elif str(message.author) in line:
								yield from message.channel.send("Woah there, {}, you already whitelisted one key! Remove the old one first.".format(str(message.author).split("#")[0]))

						search.close()

					somefile = open(whitelist, "a")
					somefile.write(ckey+"="+str(message.author))
					somefile.write("\n")
					somefile.close()
					somefile2 = open("/home/1713/civ13-tdm/SQL/whitelist.txt", "a")
					somefile2.write(ckey+"="+str(message.author))
					somefile2.write("\n")
					somefile2.close()

					yield from message.channel.send("{} has been added to the whitelist.".format(ckey))
				else:
					yield from message.channel.send("Rejected! You need to have at least the [Brother At Arms] rank.")
			else:
				yield from message.channel.send("Wrong format. Please try '!s whitelistme [ckey].'")

		elif message.content.startswith("unwhitelistme"):

			accepted = False
			for role in message.author.roles:
				if role.name == "Admiral" or role.name == "Captain" or role.name == "Lieutenant" or role.name == "Footman" or role.name == "Brother At Arms" or role.name == "Knight":
					accepted = True
					break
			if accepted:

				removed = "N/A"

				wlist = "/home/1713/civ13-rp/SQL/whitelist.txt"

				open(wlist, "a").close()

				f = open(wlist, "r")
				lines = f.readlines()
				f.close()
				f = open(list, "w")
				for line in lines:
					if not str(message.author) in line:
						f.write(line)
					else:
						removed = line.split("=")[0]

				f.close()
				list2 = "/home/1713/civ13-tdm/SQL/whitelist.txt"

				open(list2, "a").close()

				f2 = open(list2, "r")
				lines2 = f2.readlines()
				f2.close()
				f2 = open(list2, "w")
				for line2 in lines2:
					if not str(message.author) in line2:
						f2.write(line2)
					else:
						removed = line2.split("=")[0]

				f2.close()

				yield from message.channel.send("Ckey {} has been removed from the whitelist.".format(removed))
			else:
				yield from message.channel.send("Rejected! You need to have at least the [Brother At Arms] rank.")


		elif message.content.startswith("hostciv"):

			accepted = False
			for role in message.author.roles:
				if role.name == "Admiral" or role.name == "Captain" or role.name == "Lieutenant":
					accepted = True
					break
			if accepted:
				yield from message.channel.send("Please wait, updating the code...")
				os.system('sudo python3 /home/1713/civ13-rp/scripts/updateserverabspaths.py')
				yield from message.channel.send("Updated the code.")
				os.system('sudo rm -f /home/1713/civ13-rp/serverdata.txt')
				os.system('sudo DreamDaemon /home/1713/civ13-rp/civ13.dmb 1715 -trusted -webclient -logself &')
				yield from message.channel.send("Attempted to bring up Civilization 13 (Main Server) <byond://51.254.161.128:1715>")
				time.sleep(10) # ditto
				os.system('sudo python3 /home/1713/civ13-rp/scripts/killsudos.py')
			else:
				yield from message.channel.send("Denied!")

		elif message.content.startswith("killciv"):

			accepted = False
			for role in message.author.roles:
				if role.name == "Admiral" or role.name == "Captain" or role.name == "Lieutenant":
					accepted = True
					break

			if accepted:
				os.system('sudo python3 /home/1713/civ13-rp/scripts/killciv13.py')
				yield from message.channel.send("Attempted to kill Civilization 13 Server.")
			else:
				yield from message.channel.send("Denied!")
		elif message.content.startswith("restartciv"):

			accepted = False
			for role in message.author.roles:
				if role.name == "Admiral" or role.name == "Captain" or role.name == "Lieutenant":
					accepted = True
					break

			if accepted:
				os.system('sudo python3 /home/1713/civ13-rp/scripts/killciv13.py')
				yield from message.channel.send("Attempted to kill Civilization 13 Server.")
				os.system('sudo python3 /home/1713/civ13-rp/scripts/updateserverabspaths.py')
				yield from message.channel.send("Updated the code.")
				os.system('sudo rm -f /home/1713/civ13-rp/serverdata.txt')
				os.system('sudo DreamDaemon /home/1713/civ13-rp/civ13.dmb 1715 -trusted -webclient -logself &')
				yield from message.channel.send("Attempted to bring up Civilization 13 (Main Server) <byond://51.254.161.128:1715>")
				time.sleep(10) # ditto
				os.system('sudo python3 /home/1713/civ13-rp/scripts/killsudos.py')
			else:
				yield from message.channel.send("Denied!")
		elif message.content.startswith("restarttdm"):

			accepted = False
			for role in message.author.roles:
				if role.name == "Admiral" or role.name == "Captain" or role.name == "Lieutenant":
					accepted = True
					break

			if accepted:
				os.system('sudo python3 /home/1713/civ13-tdm/scripts/killciv13.py')
				yield from message.channel.send("Attempted to kill Civilization 13 TDM Server.")
				os.system('sudo python3 /home/1713/civ13-tdmp/scripts/updateserverabspaths.py')
				yield from message.channel.send("Updated the code.")
				os.system('sudo rm -f /home/1713/civ13-tdm/serverdata.txt')
				os.system('sudo DreamDaemon /home/1713/civ13-tdm/civ13.dmb 1714 -trusted -webclient -logself &')
				yield from message.channel.send("Attempted to bring up Civilization 13 (TDM Server) <byond://51.254.161.128:1714>")
				time.sleep(10) # ditto
				os.system('sudo python3 /home/1713/civ13-tdm/scripts/killsudos.py')
			else:
				yield from message.channel.send("Denied!")
		elif message.content.startswith("ping"):
			yield from message.channel.send("pong!")
			
		elif message.content.startswith("mapswap"):

			accepted = False
			for role in message.author.roles:
				if role.name == "Admiral" or role.name == "Captain" or role.name == "Knight":
					accepted = True
					break

			if accepted:
				split_message = message.content.split("mapswap ")
				if len(split_message) > 1 and len(split_message[1]) > 0:
					mapto = split_message[1]
					mapto = mapto.upper()
					yield from message.channel.send("Changing map to {}...".format(mapto))
					os.system('sudo python3 /home/1713/civ13-rp/scripts/mapswap.py {}'.format(mapto))
					yield from message.channel.send("Sucessfully changed map to {}.".format(mapto))

			else:
				yield from message.channel.send("Denied!")


		elif message.content.startswith("hosttdm"):

			accepted = False
			for role in message.author.roles:
				if role.name == "Admiral" or role.name == "Captain":
					accepted = True
					break
			if accepted:
				yield from message.channel.send("Please wait, updating the code...")
				os.system('sudo python3 /home/1713/civ13-tdm/scripts/updateserverabspaths.py')
				yield from message.channel.send("Updated the code.")
				os.system('sudo rm -f /home/1713/civ13-tdm/serverdata.txt')
				os.system('sudo DreamDaemon /home/1713/civ13-tdm/civ13.dmb 1714 -trusted -webclient -logself &')
				yield from message.channel.send("Attempted to bring up Civilization 13 (TDM Server) <byond://51.254.161.128:1714>")
				time.sleep(10) # ditto
				os.system('sudo python3 /home/1713/civ13-tdm/scripts/killsudos.py')
			else:
				yield from message.channel.send("Denied!")

		elif message.content.startswith("killtdm"):

			accepted = False
			for role in message.author.roles:
				if role.name == "Admiral" or role.name == "Captain":
					accepted = True
					break

			if accepted:
				os.system('sudo python3 /home/1713/civ13-tdm/scripts/killciv13.py')
				yield from message.channel.send("Attempted to kill Civilization 13 (TDM Server).")
			else:
				yield from message.channel.send("Denied!")

		elif message.content.startswith("tdmmapswap"):

			accepted = False
			for role in message.author.roles:
				if role.name == "Admiral" or role.name == "Captain" or role.name == "Knight":
					accepted = True
					break
			if accepted:
				split_message = message.content.split("mapswap ")
				if len(split_message) > 1 and len(split_message[1]) > 0:
					mapto = split_message[1]
					mapto = mapto.upper()
					yield from message.channel.send("Changing map to {}...".format(mapto))
					os.system('sudo python3 /home/1713/civ13-tdm/scripts/mapswap.py {}'.format(mapto))
					yield from message.channel.send("Sucessfully changed map to {}.".format(mapto))
			else:
				yield from message.channel.send("Denied!")

		elif message.content.startswith("bancheck"):
			split_message = message.content.split("bancheck ")
			if len(split_message) > 1 and len(split_message[1]) > 0:
				ckey = split_message[1]
				ckey = ckey.lower()
				ckey = ckey.replace('_','')
				ckey = ckey.replace(' ','')
				banreason = "unknown"
				found = 0
				filecheck1 = Path("/home/1713/civ13-rp/SQL/bans.txt")
				filecheck2 = Path("/home/1713/civ13-tdm/SQL/bans.txt")
				if (filecheck1.is_file()):
					open(filecheck1, "a").close()
					with open(filecheck1, "r") as search:
						for line in search:
							line = line.rstrip()	# remove '\n' at end of line
							line.replace("|||","")
							linesplit = line.split(";")
							if len(linesplit)>=8 and linesplit[8] == ckey:
								found = 1
								banreason = linesplit[3]
								bandate = linesplit[5]
								banner = linesplit[4]
								exp_date = (round(int(linesplit[6])/10))+946684800
								exp_date = datetime.utcfromtimestamp(exp_date).strftime("%Y-%m-%d %H:%M:%S")
								yield from message.channel.send("**{}** has been banned from *Nomads* on **{}** for **{}** by {}. Expires on {}.".format(ckey,bandate,banreason,banner,exp_date))
						search.close()
				if (filecheck2.is_file()):
					open(filecheck2, "a").close()
					with open(filecheck2, "r") as search:
						for line in search:
							line = line.rstrip()	# remove '\n' at end of line
							line.replace("|||","")
							linesplit = line.split(";")
							if len(linesplit)>=8 and linesplit[8] == ckey:
								found = 1
								banreason = linesplit[3]
								bandate = linesplit[5]
								banner = linesplit[4]
								exp_date = (round(int(linesplit[6])/10))+946684800
								exp_date = datetime.utcfromtimestamp(exp_date).strftime("%Y-%m-%d %H:%M:%S")
								yield from message.channel.send("**{}** has been banned from *TDM* on **{}** for **{}** by {}. Expires on {}.".format(ckey,bandate,banreason,banner,exp_date))
						search.close()
				if (found == 0):
					yield from message.channel.send("No bans were found for **{}**.".format(ckey))
			else:
				yield from message.channel.send("Wrong format. Please try '!s bancheck [ckey].'")


		elif message.content.startswith('serverstatus'):
			_1714 = not portIsAvailable(1714)
			server_is_up = (_1714)
			if not server_is_up:
				embed = discord.Embed(color=0x00ff00)
				embed.add_field(name="TDM Server Status",value="Offline", inline=False)
				yield from client.message.channel.send(embed=embed)
				return
			else:
				data = None;
				if _1714:
					if os.path.isfile('/home/1713/civ13-tdm/serverdata.txt') == True:
						data = codecs.open('/home/1713/civ13-tdm/serverdata.txt', encoding='utf-8').read()
				else:
					embed = discord.Embed(color=0x00ff00)
					embed.add_field(name="TDM Server Status",value="Offline", inline=False)
					yield from message.channel.send(embed=embed)
					return

				data = data.replace('<b>Address</b>: ', '')
				data = data.replace('<b>Map</b>: ', '')
				data = data.replace('<b>Gamemode</b>: ', '')
				data = data.replace('<b>Players</b>:','')
				data = data.replace('</b>','')
				data = data.replace('<b>','')
				data = data.split(";")
				#embed = discord.Embed(title="**Civ13 Bot**", color=0x00ff00)
				embed = discord.Embed(color=0x00ff00)
				embed.add_field(name="TDM Server Status",value="Online", inline=False)
				embed.add_field(name="Address", value='<'+data[1]+'>', inline=False)
				embed.add_field(name="Map", value=data[2], inline=False)
				embed.add_field(name="Gamemode", value=data[3], inline=False)
				embed.add_field(name="Players", value=data[4], inline=False)


				yield from message.channel.send(embed=embed)
			_1715 = not portIsAvailable(1715)
			server_is_up = (_1715)
			if not server_is_up:
				embed = discord.Embed(color=0x00ff00)
				embed.add_field(name="Nomads Server Status",value="Offline", inline=False)
				yield from message.channel.send(embed=embed)
				return
			else:
				data = None;
				if _1714:
					if os.path.isfile('/home/1713/civ13-rp/serverdata.txt') == True:
						data = codecs.open('/home/1713/civ13-rp/serverdata.txt', encoding='utf-8').read()
				else:
					embed = discord.Embed(color=0x00ff00)
					embed.add_field(name="Nomads Server Status",value="Offline", inline=False)
					yield from message.channel.send(embed=embed)
					return

				data = data.replace('<b>Address</b>: ', '')
				data = data.replace('<b>Map</b>: ', '')
				data = data.replace('<b>Gamemode</b>: ', '')
				data = data.replace('<b>Players</b>:','')
				data = data.replace('</b>','')
				data = data.replace('<b>','')
				data = data.split(";")
				#embed = discord.Embed(title="**Civ13 Bot**", color=0x00ff00)
				embed = discord.Embed(color=0x00ff00)
				embed.add_field(name="Nomads Server Status",value="Online", inline=False)
				embed.add_field(name="Address", value='<'+data[1]+'>', inline=False)
				embed.add_field(name="Map", value=data[2], inline=False)
				embed.add_field(name="Gamemode", value=data[3], inline=False)
				embed.add_field(name="Players", value=data[4], inline=False)


				yield from message.channel.send(embed=embed)

client.run('NDcxNDAwMTY4OTczOTI2NDMw.Xr76Ew.5h-Z7OHnpZDJzgktSitCQmhMYHc')
