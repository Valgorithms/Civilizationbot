// Fixed-file startup handshake for the trusted synthetic derivatives only.
// The observer owns the PID and socket proof. These receipts do not prove it.
// No intended outbound activity. No claim of OS egress containment.
#define SS_SCHEMA "civilizationbot.synthetic-startup.v1"

/client/New()
	del(src)

/world/Topic()
	return null

/proc/ss_emit(phase, nonce, image)
	if(!(phase in list("ready", "closed", "started"))) return 0
	var/name = "startup-[phase]"
	if(fexists("[name].json") || fexists("[name].done")) return 0
	var/list/receipt = list("schema" = SS_SCHEMA, "nonce" = nonce, "image" = image, "phase" = phase, "runtime" = "[world.byond_version].[world.byond_build]", "world_port" = world.port)
	if(!text2file(json_encode(receipt), "[name].json")) return 0
	// Marker is written last. The parent bounds and validates both files;
	// partial/change/error evidence never releases fixture work.
	return text2file(nonce, "[name].done")

/proc/ss_ack(phase, nonce, image)
	var/name = "startup-[phase].ack"
	if(!fexists(name)) return 0
	var/raw = file2text(name)
	if(!istext(raw) || length(raw) > 1024) return 0
	var/list/ack = json_decode(raw)
	return istype(ack, /list) && ack.len == 4 && ack["schema"] == SS_SCHEMA && ack["nonce"] == nonce && ack["image"] == image && ack["phase"] == phase

/proc/ss_wait(phase, nonce, image)
	// No deadline extension: parent enforces1s from launch for ALL startup
	// phases and10s including fixture execution and owned-child cleanup.
	for(var/attempt = 1, attempt <= 4, attempt++)
		if(ss_ack(phase, nonce, image)) return 1
		sleep(1)
	return 0

/proc/ss_startup(image)
	if(!(image in list("save_bridge", "fixture_generator"))) return 0
	if(world.byond_version != 516 || world.byond_build != 1688 || world.port != 25444) return 0
	var/raw = file2text("startup-config.json")
	if(!istext(raw) || length(raw) > 1024) return 0
	var/list/config = json_decode(raw)
	if(!istype(config, /list) || config.len != 4 || config["schema"] != SS_SCHEMA || config["image"] != image || config["port"] != 25444) return 0
	var/nonce = config["nonce"]
	if(!istext(nonce) || length(nonce) != 32) return 0
	var/regex/R = regex(@"^[a-f0-9]{32}$")
	if(!R.Find(nonce)) return 0
	if(!ss_emit("ready", nonce, image) || !ss_wait("close", nonce, image)) return 0
	world.OpenPort("none")
	if(world.port || !ss_emit("closed", nonce, image) || !ss_wait("release", nonce, image)) return 0
	if(world.port || !ss_emit("started", nonce, image)) return 0
	return 1
