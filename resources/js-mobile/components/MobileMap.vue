<script setup>
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { loadGoogleMaps } from '../utils/googleMaps';
const props=defineProps({center:{type:Object,default:null},destination:{type:Object,default:null},zoom:{type:Number,default:14}});
const el=ref(null);let map=null,originMarker=null,destinationMarker=null,directions=null,renderer=null;
async function initialize(){const maps=await loadGoogleMaps();if(!maps||!el.value)return;const center=props.center||{lat:-2.1709,lng:-79.9224};map=new maps.Map(el.value,{center,zoom:props.zoom,disableDefaultUI:true,zoomControl:true,gestureHandling:'greedy',styles:[{featureType:'poi',stylers:[{visibility:'off'}]}]});originMarker=new maps.Marker({map,position:center,icon:{path:maps.SymbolPath.CIRCLE,scale:8,fillColor:'#12a779',fillOpacity:1,strokeColor:'#fff',strokeWeight:3}});directions=new maps.DirectionsService();renderer=new maps.DirectionsRenderer({map,suppressMarkers:true,polylineOptions:{strokeColor:'#147a5a',strokeWeight:5}});updateRoute()}
function updateRoute(){if(!map||!props.center)return;originMarker?.setPosition(props.center);if(!props.destination){destinationMarker?.setMap(null);renderer?.set('directions',null);map.panTo(props.center);return}const maps=window.google.maps;if(!destinationMarker)destinationMarker=new maps.Marker({map,icon:{path:'M 0,-1 1,0 0,1 -1,0 z',scale:8,fillColor:'#ef5b61',fillOpacity:1,strokeColor:'#fff',strokeWeight:2}});destinationMarker.setMap(map);destinationMarker.setPosition(props.destination);directions.route({origin:props.center,destination:props.destination,travelMode:maps.TravelMode.DRIVING},(result,status)=>{if(status==='OK')renderer.setDirections(result);else{const bounds=new maps.LatLngBounds();bounds.extend(props.center);bounds.extend(props.destination);map.fitBounds(bounds)}})}
watch(()=>[props.center?.lat,props.center?.lng,props.destination?.lat,props.destination?.lng],updateRoute);onMounted(initialize);onBeforeUnmount(()=>{originMarker?.setMap(null);destinationMarker?.setMap(null);renderer?.setMap(null)});
function setView(position, zoom=props.zoom){if(!map||!position)return;map.setZoom(zoom);map.panTo(position)}
defineExpose({setView});
</script>
<template><div ref="el" class="mobile-map"><div class="map-loading">Cargando mapa…</div></div></template>
<style scoped>.mobile-map{width:100%;min-width:0;max-width:100%;height:100%;min-height:18rem;overflow:hidden;background:#dfe8e3}.map-loading{height:100%;display:grid;place-items:center;color:#68756f;font-size:.8rem}</style>
