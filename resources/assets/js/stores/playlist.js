import _ from 'lodash';

import http from '../services/http';
import stub from '../stubs/playlist';
import sharedStore from './shared';
import songStore from './song';

export default {
    stub,
    
    state: {
        playlists: [],
    },

    init() {
        this.state.playlists = sharedStore.state.playlists;

        _.each(this.state.playlists, this.getSongs);
    },

    all() {
        return this.state.playlists;
    },

    /**
     * Get all songs in a playlist.
     *
     * return {Array}
     */
    getSongs(playlist) {
        return (playlist.songs = songStore.byIds(playlist.songs));
    },

    /**
     * Create a new playlist, optionally with its songs.
     * 
     * @param  {string}   name  Name of the playlist
     * @param  {Array}    songs An array of song objects
     * @param  {Function} cb
     */
    store(name, songs, cb = null) {
        if (songs.length) {
            // Extract the IDs from the song objects.
            songs = _.pluck(songs, 'id');
        }

        http.post('playlist', { name, songs }, response => {
            var playlist = response.data;
            playlist.songs = songs;
            this.getSongs(playlist);
            this.state.playlists.push(playlist);

            if (cb) {
                cb();
            }
        });
    },

    delete(playlist, cb = null) {
        http.delete(`playlist/${playlist.id}`, {}, () => {
            this.state.playlists = _.without(this.state.playlists, playlist);

            if (cb) {
                cb();
            }
        });
    },

    addSongs(playlist, songs, cb = null) {
        playlist.songs = _.union(playlist.songs, songs);

        http.put(`playlist/${playlist.id}/sync`, { songs: _.pluck(playlist.songs, 'id') }, () => {
            if (cb) {
                cb();
            }
        });
    },

    removeSongs(playlist, songs, cb = null) {
        playlist.songs = _.difference(playlist.songs, songs);
        
        http.put(`playlist/${playlist.id}/sync`, { songs: _.pluck(playlist.songs, 'id') }, () => {
            if (cb) {
                cb();
            }
        });
    },

    update(playlist, cb = null) {
        http.put(`playlist/${playlist.id}`, { name: playlist.name }, () => {
            if (cb) {
                cb();
            }
        });
    },
};
