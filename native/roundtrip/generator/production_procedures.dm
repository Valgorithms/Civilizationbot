/map_storage/proc/encode_saved_value(savefile/F, value)
	if(istype(value, /obj/map_metadata))
		return list("kind" = "map")
	if(istype(value, /area))
		var/area/AR = value
		if(zone_export_state) return encode_zone_reference(AR)
		var/turf/T = locate(/turf) in AR
		if(!T)
			throw EXCEPTION("Referenced area has no world turf: [AR.type]")
		return list("kind" = "area", "x" = T.x, "y" = T.y, "z" = T.z, "type" = AR.type)
	if(islist(value))
		var/list/L = value
		var/id = saving_lists["\ref[L]"]
		if(!id || saving_list_pins[id] != L)
			id = saving_list_pins.len + 1
			saving_list_pins += list(L)
			saving_lists["\ref[L]"] = id
			var/list/snapshot = L.Copy()
			F["/lists/[id]/length"] << snapshot.len
			for(var/i = 1, i <= snapshot.len, i++)
				save_work_check()
				TICK_CHECK
				var/key = snapshot[i]
				var/encoded_key = encode_saved_value(F, key)
				var/encoded_value = encode_saved_value(F, (isnum(key) || isnull(key)) ? null : snapshot[key])
				F["/lists/[id]/[i]/key"] << encoded_key
				F["/lists/[id]/[i]/value"] << encoded_value
		return list("kind" = "list", "ref" = id)
	if(isturf(value))
		var/turf/T = value
		return list("kind" = "turf", "x" = T.x, "y" = T.y, "z" = T.z)
	if(istype(value, /datum))
		var/id = BuildVarDirectory(F, value, 1)
		return list("kind" = "entry", "ref" = id)
	return list("kind" = "value", "value" = value)
/map_storage/proc/encode_saved_contents(savefile/F, datum/save_export_frame/frame)
	if(F["/format_version"] != 3)
		if(frame.content_refs.len > 500)
			throw EXCEPTION("Dense contents require save format 3")
		return list2params(frame.content_refs)
	var/list/chunks = list()
	for(var/start = 1, start <= frame.content_refs.len, start += 500)
		chunks += list2params(frame.content_refs.Copy(start, min(start + 500, frame.content_refs.len + 1)))
		save_work_check()
		require_export_frame(frame)
	return chunks
/map_storage/proc/entry_path(savefile/F, ref)
	if(F["/format_version"] == 2 || F["/format_version"] == 3)
		return "/entries/[round((text2num("[ref]") - 1) / 512)]/[ref]"
	return "/entries/[ref]"
